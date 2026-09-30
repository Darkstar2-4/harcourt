<?php
/*
Read-only Google Sheets client using a Google Cloud service account.

Signs the service account JWT with openssl and calls the Sheets REST API with
curl, so no Google client library is required. The sheet must be shared (Viewer)
with the service account's client_email.

.env settings:
    GOOGLE_SHEETS_CLIENT_EMAIL  client_email from the service account JSON key
    GOOGLE_SHEETS_PRIVATE_KEY   private_key from the JSON key, in double quotes, with \n kept as literal \n
*/
class google_sheets_class {
    var $client_email;
    var $private_key;
    var $access_token="";
    var $error="";
    var $title="";
    var $tab="";
    const scope="https://www.googleapis.com/auth/spreadsheets.readonly";
    const token_url="https://oauth2.googleapis.com/token";
    const api_url="https://sheets.googleapis.com/v4/spreadsheets/";

    function __construct($client_email, $private_key) {
        $this->client_email=trim($client_email);
        $this->private_key=str_replace('\n', "\n", trim($private_key));
    }
    static function from_env($env) {
        return new google_sheets_class($env['GOOGLE_SHEETS_CLIENT_EMAIL'], $env['GOOGLE_SHEETS_PRIVATE_KEY']);
    }
    function configured() {
        return ( (strlen($this->client_email)) && (strlen($this->private_key)) );
    }
    static function base64url($data) {
        return rtrim(strtr(base64_encode($data), "+/", "-_"), "=");
    }
    function jwt($now=0) {
        if (!$now) $now=time();
        $header=array("alg"=>"RS256", "typ"=>"JWT");
        $claims=array("iss"=>$this->client_email, "scope"=>self::scope, "aud"=>self::token_url, "iat"=>$now, "exp"=>$now + 3600);
        $unsigned=self::base64url(json_encode($header)) . "." . self::base64url(json_encode($claims));
        $key=openssl_pkey_get_private($this->private_key);
        if (!$key) {
            $this->error="Invalid GOOGLE_SHEETS_PRIVATE_KEY";
            return "";
        }
        if (!openssl_sign($unsigned, $signature, $key, OPENSSL_ALGO_SHA256)) {
            $this->error="Unable to sign Google service account token";
            return "";
        }
        return $unsigned . "." . self::base64url($signature);
    }
    function authenticate() {
        if (strlen($this->access_token)) return TRUE;
        if (!$this->configured()) {
            $this->error="Google Sheets credentials are not configured";
            return FALSE;
        }
        $jwt=$this->jwt();
        if (!$jwt) return FALSE;
        $response=$this->request(self::token_url, array("grant_type"=>"urn:ietf:params:oauth:grant-type:jwt-bearer", "assertion"=>$jwt));
        if (!$response) return FALSE;
        $this->access_token=$response['access_token'];
        return TRUE;
    }
    // Returns every row of the tab (first tab when $tab is blank) as formatted values, or FALSE on error
    function rows($spreadsheet_id, $tab="") {
        if (!$this->authenticate()) return FALSE;
        $url=self::api_url . rawurlencode($spreadsheet_id);
        $response=$this->request($url . "?" . http_build_query(array("fields"=>"properties.title,sheets.properties.title")));
        if (!$response) return FALSE;
        $this->title=$response['properties']['title'];
        $tabs=array();
        foreach ($response['sheets'] as $sheet) {
            $tabs[]=$sheet['properties']['title'];
        }
        switch (TRUE) {
          case (!sizeof($tabs)):
            $this->error="No tabs found in Google Sheet";
            return FALSE;
          case (!strlen($tab)):
            $tab=$tabs[0];
            break;
          case (!in_array($tab, $tabs)):
            $this->error="Tab '{$tab}' not found in Google Sheet";
            return FALSE;
        }
        $this->tab=$tab;
        $range="'" . str_replace("'", "''", $tab) . "'";
        $response=$this->request($url . "/values/" . rawurlencode($range) . "?" . http_build_query(array("valueRenderOption"=>"FORMATTED_VALUE")));
        if (!$response) return FALSE;
        return ( (isset($response['values'])) ? $response['values'] : array() );
    }
    // Writes rows to a CSV file, padding short rows (the API drops trailing blank cells) to the header width
    static function csv($rows, $filename) {
        $width=0;
        foreach ($rows as $row) {
            $width=max($width, sizeof($row));
        }
        $fh=fopen($filename, "w");
        foreach ($rows as $row) {
            fputcsv($fh, array_pad($row, $width, ""));
        }
        fclose($fh);
    }
    protected function request($url, $post=array()) {
        $ch=curl_init($url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, TRUE);
        curl_setopt($ch, CURLOPT_TIMEOUT, 30);
        if (sizeof($post)) {
            curl_setopt($ch, CURLOPT_POST, TRUE);
            curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($post));
        }
        if (strlen($this->access_token)) curl_setopt($ch, CURLOPT_HTTPHEADER, array("Authorization: Bearer " . $this->access_token));
        $body=curl_exec($ch);
        $status=curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curl_error=curl_error($ch);
        curl_close($ch);
        $response=json_decode($body, TRUE);
        if ($body === FALSE) {
            $this->error="Google request failed: {$curl_error}";
            return FALSE;
        }
        $message=(isset($response['error']['message'])) ? $response['error']['message'] : ( (isset($response['error_description'])) ? $response['error_description'] : "HTTP {$status}" );
        switch (TRUE) {
          case ($status == 404):
            $this->error="Google Sheet not found, check HCERT_SHEET_ID";
            return FALSE;
          case ($status == 403):
            $this->error="Google Sheet access denied ({$message}). Share the sheet with {$this->client_email}";
            return FALSE;
          case ($status != 200):
          case (!is_array($response)):
            $this->error="Google request failed: {$message}";
            return FALSE;
        }
        return $response;
    }
}
?>
