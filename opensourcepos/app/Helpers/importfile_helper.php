<?php

/**
 * @param array $stock_locations
 * @param array $attributes
 * @return string
 */
function generate_import_items_csv(array $stock_locations, array $attributes): string
{
    $csv_headers = pack('CCC', 0xef, 0xbb, 0xbf);    // Encode the Byte-Order Mark (BOM) so that UTF-8 File headers display properly in Microsoft Excel
    $csv_headers .= 'Id,Barcode,"Item Name",Category,"Supplier ID","Cost Price","Unit Price","Tax 1 Name","Tax 1 Percent","Tax 2 Name","Tax 2 Percent","Reorder Level",Description,"Allow Alt Description","Item has Serial Number",Image,HSN';
    $csv_headers .= generate_stock_location_headers($stock_locations);
    $csv_headers .= generate_attribute_headers($attributes);

    return $csv_headers;
}

/**
 * Builds a full import-compatible CSV (BOM + header + data rows).
 * Each data row is an associative array keyed by the template header labels
 * (e.g. "Item Name", "location_stock", "attribute_Expiry Date").
 *
 * @param array $stock_locations
 * @param array $attributes
 * @param array $data_rows
 * @return string
 */
function generate_import_items_csv_with_data(array $stock_locations, array $attributes, array $data_rows = []): string
{
    $csv = generate_import_items_csv($stock_locations, $attributes);

    if ($data_rows === []) {
        return $csv;
    }

    $headers = str_getcsv(substr($csv, 3));
    $handle = fopen('php://temp', 'r+');

    foreach ($data_rows as $row) {
        $ordered = [];
        foreach ($headers as $header) {
            $ordered[] = $row[$header] ?? '';
        }
        fputcsv($handle, $ordered);
    }

    rewind($handle);
    $body = stream_get_contents($handle);
    fclose($handle);

    return rtrim($csv, "\r\n") . "\n" . rtrim((string)$body, "\r\n") . "\n";
}

/**
 * @param array $locations
 * @return string
 */
function generate_stock_location_headers(array $locations): string
{
    $location_headers = '';

    foreach ($locations as $location_name) {
        $location_headers .= ',"location_' . $location_name . '"';
    }

    return $location_headers;
}

/**
 * @param array $attribute_names
 * @return string
 */
function generate_attribute_headers(array $attribute_names): string
{
    $attribute_headers = '';
    unset($attribute_names[-1]);

    foreach ($attribute_names as $attribute_name) {
        $attribute_headers .= ',"attribute_' . $attribute_name . '"';
    }

    return $attribute_headers;
}

/**
 * Processes a CSV file and returns it.
 * @param string $file_name
 * @return array A multidimensional array of rows found within the file and their associative key/value pairs.
 */
function get_csv_file(string $file_name): array
{
    $csv_rows = [];

    if (($csv_file = fopen($file_name, 'r')) === false) {
        return $csv_rows;
    }

    helper('security');

    // Skip Byte-Order Mark
    if (bom_exists($csv_file)) {
        fseek($csv_file, 3);
    }

    $headers = fgetcsv($csv_file);
    if ($headers === false || $headers === [null]) {
        fclose($csv_file);

        return $csv_rows;
    }

    // Normalize header labels so export/import column names stay aligned
    $headers = array_map(static function ($header) {
        return trim((string)$header);
    }, $headers);

    while (($row = fgetcsv($csv_file)) !== false) {
        if ($row === [null]) {
            continue;
        }

        // Skip malformed rows whose column count does not match the template header
        if (count($row) !== count($headers)) {
            continue;
        }

        $combined = array_combine($headers, $row);
        if ($combined !== false) {
            $csv_rows[] = $combined;
        }
    }

    fclose($csv_file);

    return $csv_rows;
}

/**
 * @param $file_handle
 * @return bool
 */
function bom_exists(&$file_handle): bool
{
    $result        = false;
    $candidate    = fread($file_handle, 3);

    rewind($file_handle);

    $bom = pack('CCC', 0xef, 0xbb, 0xbf);

    if (0 === strncmp($candidate, $bom, 3)) {
        $result = true;
    }

    return $result;
}

/**
 * Checks that the parsed item import rows expose the columns the current
 * template requires. A file whose header row does not match the template
 * (for example when the "Id" column is missing, an attribute column was
 * renamed, or a stock location was added since the file was last downloaded)
 * would otherwise trigger "Undefined array key" errors while building each
 * item. The required columns are derived from generate_import_items_csv(),
 * the same function that builds the downloadable template, so location and
 * attribute columns stay in sync automatically.
 *
 * @param array $csv_rows Rows returned by get_csv_file().
 * @param array $stock_locations Stock locations, as returned by Stock_location::get_allowed_locations().
 * @param array $attribute_names Attribute definition names, as returned by Attribute::get_definition_names().
 * @return bool True when the required columns are present.
 */
function csvImportHasRequiredItemHeaders(array $csv_rows, array $stock_locations, array $attribute_names): bool
{
    if (empty($csv_rows)) {
        return false;
    }

    $template_header_line = substr(generate_import_items_csv($stock_locations, $attribute_names), 3);
    $required_headers = str_getcsv($template_header_line);

    $present_headers = array_keys(reset($csv_rows));

    return empty(array_diff($required_headers, $present_headers));
}
