<?php
namespace App\Assistants;

use Carbon\Carbon;

class CommonPdfAssistant extends PdfClient
{
    const PACKAGE_TYPE_MAP = [
        "EW-Paletten" => "PALLET_OTHER",
        "EPAL"        => "EPAL",
        "EUR"         => "EPAL",
        "Euro"        => "EPAL",
        "Pallet"      => "pallet",
        "Paletten"    => "pallet",
        "Ladung"      => "CARTON",
        "Karton"      => "carton",
        "Carton"      => "carton",
        "CARTON"      => "carton",
        "Stück"       => "OTHER",
        "Pcs"         => "other",
        "Pieces"      => "other",
        "Other"       => "other",
        "GITTER"      => "other",
        "Box"         => "other",
    ];

    public static function validateFormat(array $lines)
    {
        // Always try this assistant as fallback
        return true;
    }

    public function processLines(array $lines, ?string $attachment_filename = null)
    {
        $data = $this->extractCommonData($lines, $attachment_filename);

        // Simple PHP validation instead of JSON Schema
        if (empty($data['customer']['details']['company'])) {
            throw new \Exception("Missing customer company in extracted data");
        }

        if (empty($data['order_reference'])) {
            throw new \Exception("Missing order reference in extracted data");
        }

        if (empty($data['loading_locations']) || empty($data['destination_locations'])) {
            throw new \Exception("Missing loading or destination locations");
        }

        if (empty($data['cargos'])) {
            $data['cargos'] = [
                [
                    'title'         => 'General cargo',
                    'package_count' => 1,
                    'package_type'  => 'other',
                ],
            ];
        }

        // Now create the order
        $this->createOrder($data);
    }

    protected function extractCommonData(array $lines, ?string $attachment_filename = null)
    {
        $data = [
            'customer'              => $this->extractCustomer($lines),
            'loading_locations'     => $this->extractLoadingLocations($lines),
            'destination_locations' => $this->extractDestinationLocations($lines),
            'cargos'                => $this->extractCargos($lines),
            'order_reference'       => $this->extractOrderReference($lines),
            'attachment_filenames'  => [mb_strtolower($attachment_filename ?? '')],
        ];

        if (empty($data['order_reference'])) {
            throw new \Exception("Missing order reference in PDF");
        }

        $transport_numbers = $this->extractTransportNumbers($lines);
        if ($transport_numbers) {
            $data['transport_numbers'] = $transport_numbers;
        }

        $freight_data = $this->extractFreightData($lines);
        if ($freight_data) {
            $data = array_merge($data, $freight_data);
        }

        return $data;
    }

    protected function extractCustomer(array $lines)
    {
        $company_patterns = [
            '/^(.+(?:GmbH|UAB|a\.s\.|Ltd|Inc|Corp|Company|Logistic|Transport).*?)$/i',
            '/^(.+),\s*(.+),\s*([A-Z]{1,2}[-\s]?\d{4,})\s*(.+)$/i',
        ];

        $company_info = null;
        foreach ($lines as $line) {
            foreach ($company_patterns as $pattern) {
                if (preg_match($pattern, trim($line), $matches)) {
                    $company_info = $matches[1];
                    break 2;
                }
            }
        }

        if (!$company_info) {
            throw new \Exception("Invalid PDF: missing customer company");
        }

        return [
            'side'    => 'none',
            'details' => [
                'company' => $company_info,
            ],
        ];
    }

    protected function extractOrderReference(array $lines)
    {
        $patterns = [
            '/(?:order|reference|ref|nr|no|number|auftrag|užsakymas)[\s\.:]*([A-Z0-9\-]+)/i',
            '/([A-Z]{1,3}\d{6,})/i',
            '/\*{2,}\s*([A-Z0-9\-]+)\s*\*{2,}/',
            '/(\d{7,})/i',
        ];

        foreach ($lines as $line) {
            foreach ($patterns as $pattern) {
                if (preg_match($pattern, $line, $matches)) {
                    return trim($matches[1], '* ');
                }
            }
        }

        return null;
    }

    protected function extractLoadingLocations(array $lines)
    {
        $locations = [];
        $patterns  = ['/loading/i', '/pickup/i', '/pakrovimo/i', '/abhol/i'];

        foreach ($lines as $i => $line) {
            foreach ($patterns as $p) {
                if (preg_match($p, $line)) {
                    $loc = $this->extractLocationFromContext($lines, $i);
                    if ($loc) {
                        $locations[] = $loc;
                    }
                }
            }
        }

        if (empty($locations)) {
            throw new \Exception("Invalid PDF: no valid loading location found");
        }

        return $locations;
    }

    protected function extractDestinationLocations(array $lines)
    {
        $locations = [];
        $patterns  = ['/delivery/i', '/destination/i', '/iškrovimo/i', '/ablad/i'];

        foreach ($lines as $i => $line) {
            foreach ($patterns as $p) {
                if (preg_match($p, $line)) {
                    $loc = $this->extractLocationFromContext($lines, $i);
                    if ($loc) {
                        $locations[] = $loc;
                    }
                }
            }
        }

        if (empty($locations)) {
            throw new \Exception("Invalid PDF: no valid destination location found");
        }

        return $locations;
    }

    protected function extractLocationFromContext(array $lines, int $start_index)
    {
        $address_data = ['company' => null];
        $time_data    = [];

        for ($i = $start_index; $i < min($start_index + 10, count($lines)); $i++) {
            $line = trim($lines[$i]);
            if (empty($line)) {
                continue;
            }

            // Only set company if not already set
            if (!$address_data['company'] && preg_match('/^(.+(?:GmbH|UAB|Ltd|Inc|Corp|Company).*?)$/i', $line, $matches)) {
                $address_data['company'] = $matches[1];
            }

            // parse date if present
            if (preg_match('/(\d{1,2}[\.\/\-]\d{1,2}[\.\/\-]\d{2,4})/', $line, $date_match)) {
                $time_data['datetime_from'] = $this->parseDateTime($date_match[1]);
            }
        }

        // Fallback if company not found
        if (empty($address_data['company'])) {
            $address_data['company'] = 'Unknown company';
        }

        $result = ['company_address' => $address_data];
        if (!empty($time_data)) {
            $result['time'] = $time_data;
        }

        return $result;
    }

    protected function extractCargos(array $lines)
    {
        $cargos = [];
        foreach ($lines as $i => $line) {
            if (preg_match('/(\d+)\s*x?\s*(.+)/i', $line, $m)) {
                $count = (int) $m[1];
                $desc  = trim($m[2]);
                if ($count > 0 && strlen($desc) > 2) {
                    $cargos[] = [
                        'title'         => $desc,
                        'package_count' => $count,
                        'package_type'  => $this->mapPackageType($desc),
                    ];
                }
            }
        }
        if (empty($cargos)) {
            $cargos[] = [
                'title'         => 'General cargo',
                'package_count' => 1,
                'package_type'  => 'other',
            ];
        }
        return $cargos;
    }

    protected function extractTransportNumbers(array $lines)
    {
        $nums = [];
        foreach ($lines as $line) {
            if (preg_match('/([A-Z]{1,3}\s?\d{2,4}[A-Z]{0,3})/i', $line, $m)) {
                $nums[] = trim($m[1]);
            }
        }
        return $nums ? implode(' / ', array_unique($nums)) : null;
    }

    protected function extractFreightData(array $lines)
    {
        foreach ($lines as $line) {
            if (preg_match('/(\d+(?:[,\.]\d+)?)\s*(EUR|USD|GBP|PLN)/i', $line, $m)) {
                return [
                    'freight_price'    => (float) str_replace(',', '.', $m[1]),
                    'freight_currency' => strtoupper($m[2]),
                ];
            }
        }
        return null;
    }

    protected function parseDateTime(string $str)
    {
        $formats = ['d.m.Y', 'd/m/Y', 'd-m-Y', 'Y-m-d'];
        foreach ($formats as $f) {
            try {
                return Carbon::createFromFormat($f, $str)->toIsoString();
            } catch (\Exception $e) {}
        }
        return null;
    }

    protected function mapPackageType(string $type)
    {
        foreach (static::PACKAGE_TYPE_MAP as $k => $v) {
            if (stripos($type, $k) !== false) {
                return $v;
            }

        }
        return 'other';
    }
}
