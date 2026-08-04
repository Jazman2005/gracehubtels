<?php
$log_file = 'C:\\Users\\user\\.gemini\\antigravity-ide\\brain\\ca1679b7-ace8-4495-9538-b2b4deef237c\\.system_generated\\logs\\transcript_full.jsonl';
$lines = file($log_file);
$dashboard_content = null;

// Find the last view_file response that showed lines 1 to 575
foreach(array_reverse($lines) as $line) {
    $json = json_decode($line, true);
    if ($json && $json['type'] === 'TOOL_RESPONSE' && isset($json['tool_responses'])) {
        foreach($json['tool_responses'] as $resp) {
            if ($resp['name'] === 'view_file' || $resp['name'] === 'default_api:view_file') {
                if (isset($resp['response']['output']) && strpos($resp['response']['output'], 'File Path: `file:///c:/xampp/htdocs/gracehubtels/user/dashboard.php`') !== false) {
                    if (strpos($resp['response']['output'], 'Total Lines: 575') !== false) {
                        $output = $resp['response']['output'];
                        // Extract lines
                        $start = strpos($output, "The following code has been modified");
                        if ($start !== false) {
                            $start = strpos($output, "\n", $start) + 1;
                            $end = strpos($output, "\nThe above content does NOT show", $start);
                            if ($end === false) {
                                $end = strlen($output);
                            }
                            $content = substr($output, $start, $end - $start);
                            
                            // Remove line numbers: "1: "
                            $clean = preg_replace('/^[0-9]+:\s/m', '', $content);
                            $dashboard_content = $clean;
                            break 2;
                        }
                    }
                }
            }
        }
    }
}

if ($dashboard_content) {
    file_put_contents('c:\\xampp\\htdocs\\gracehubtels\\user\\dashboard.php', $dashboard_content);
    echo "Successfully recovered dashboard.php!\n";
} else {
    echo "Could not find the full file in logs.\n";
}
