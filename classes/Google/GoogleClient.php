<?php

define('SPREADSHEET_ID', getenv('SPREADSHEET_ID'));
define('APPLICATION_NAME', getenv('APPLICATION_NAME'));
define('CREDENTIALS_PATH', getenv('CREDENTIALS_PATH'));
define('CLIENT_SECRET_PATH', getenv('CLIENT_SECRET_PATH'));

if (php_sapi_name() != 'cli') {
    throw new Exception('This application must be run on the command line.');
}


class GoogleClient
{
    protected static $_instance = null;
    protected $_service;
    protected $_client;

    /**
     * Get instance of Google Sheets Client
     *
     * @return GoogleClient|null
     */
    public static function getInstance()
    {
        if (null === self::$_instance) {
            self::$_instance = new self();
        }

        return self::$_instance;
    }

    public function __construct()
    {
        $this->_client = $this->getClient();
        $this->_service = new Google_Service_Sheets($this->_client);
    }


    /**
     * Returns an authorized API client.
     * @return Google_Client the authorized client object
     */
    private function getClient()
    {
        $client = new Google_Client();
        $client->setApplicationName(APPLICATION_NAME);
        $client->setScopes([Google_Service_Sheets::SPREADSHEETS]);
        $client->setAuthConfig(CLIENT_SECRET_PATH);
        $client->setAccessType('offline');

        // Load previously authorized credentials from a file.
        $credentialsPath = $this->expandHomeDirectory(CREDENTIALS_PATH);
        if (file_exists($credentialsPath)) {
            $accessToken = json_decode(file_get_contents($credentialsPath), true);
        } else {
            // Request authorization from the user.
            $authUrl = $client->createAuthUrl();
            printf("Open the following link in your browser:\n%s\n", $authUrl);
            print 'Enter verification code: ';
            $authCode = trim(fgets(STDIN));

            // Exchange authorization code for an access token.
            $accessToken = $client->fetchAccessTokenWithAuthCode($authCode);

            // Store the credentials to disk.
            if (!file_exists(dirname($credentialsPath))) {
                mkdir(dirname($credentialsPath), 0700, true);
            }
            file_put_contents($credentialsPath, json_encode($accessToken));
            printf("Credentials saved to %s\n", $credentialsPath);
        }
        $client->setAccessToken($accessToken);

        // Refresh the token if it's expired.
        if ($client->isAccessTokenExpired()) {
            $client->fetchAccessTokenWithRefreshToken($client->getRefreshToken());
            file_put_contents($credentialsPath, json_encode($client->getAccessToken()));
        }
        return $client;
    }

    /**
     * Expands the home directory alias '~' to the full path.
     * @param string $path the path to expand.
     * @return string the expanded path.
     */
    private function expandHomeDirectory($path)
    {
        $homeDirectory = getenv('HOME');
        if (empty($homeDirectory)) {
            $homeDirectory = getenv('HOMEDRIVE') . getenv('HOMEPATH');
        }
        return str_replace('~', realpath($homeDirectory), $path);
    }

    public function getTabs()
    {
        $tabs = array();
        $sheets = $this->_service->spreadsheets->get(SPREADSHEET_ID)->getSheets();

        foreach ($sheets as $s) {
            $tabs[] = $s['properties']['title'];
        }

        return $tabs;
    }

    public function createNewTab($title)
    {
        $sheet = new Google_Service_Sheets_BatchUpdateSpreadsheetRequest(['requests' => ['add_sheet' => ['properties' => ['title' => $title]]]]);
        $this->_service->spreadsheets->batchUpdate(SPREADSHEET_ID, $sheet);
    }

    public function getSheetValues(string $range)
    {
        return $this->_service->spreadsheets_values->get(SPREADSHEET_ID, $range)->getValues();
    }

    /**
     * @param string $sheetName
     * @param array $values
     * @return Google_Service_Sheets_ValueRange
     */
    public function setSheetValueRange($sheetName, $values)
    {
        return new Google_Service_Sheets_ValueRange([
            'majorDimension' => 'ROWS',
            'range' => $sheetName . '!A2:Z',
            'values' => $values
        ]);
    }

    public function appendHeadersRow(string $sheetName, $headers)
    {
        $range = new Google_Service_Sheets_ValueRange();
        $range->setValues(['values' => $headers]);

        $this->_service->spreadsheets_values->append(SPREADSHEET_ID, $sheetName, $range, ["valueInputOption" => "RAW"]);
    }

    /**
     * @param array $data
     * @return Google_Service_Sheets_BatchUpdateValuesRequest
     */
    public function batchRequest($data)
    {
        return new Google_Service_Sheets_BatchUpdateValuesRequest([
            'valueInputOption' => 'USER_ENTERED',
            'data' => $data
        ]);
    }

    /**
     * @param Google_Service_Sheets_BatchUpdateValuesRequest $body
     * @return Google_Service_Sheets_BatchUpdateValuesResponse
     */
    public function batchUpdate($body)
    {
        $this->_service->spreadsheets_values->batchUpdate(SPREADSHEET_ID, $body);
    }
}