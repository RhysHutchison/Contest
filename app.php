<?php

use Dotenv\Dotenv;

class app
{
    protected $_GoogleClient;
    protected $_tabs;
    protected $_lastEntrantSynced;

    public function __construct()
    {
        $dotenv = new Dotenv(__DIR__);
        $dotenv->load();

        $this->_GoogleClient = GoogleClient::getInstance();
        $this->_tabs = $this->_GoogleClient->getTabs();

        $this->contestantHandler();

        $this->commissionHandler();
    }

    private function contestantHandler()
    {
        $entrants = $this->getContestEntrants();

        // if sheet exists, get the last entrant synced to avoid duplicating the same entrant
        if (in_array('Contestants', $this->_tabs)) {
            $data = $this->_GoogleClient->getSheetValues('Contestants!A2:Z');
            $this->_lastEntrantSynced = $data[count($data) - 1];

        } else {
            // create a new sheet if the Contestants sheet doesn't exist
            $this->_GoogleClient->createNewTab('Contestants');

            // Set the headers for the $current_month sheet
            $this->setSheetHeaders('Contestants', $entrants[0]);
        }

        // sync retrieved entrants into the google contestants sheet
        $this->setSheetData('Contestants', $entrants);
    }

    private function commissionHandler()
    {
        $commissions = $this->getCommissions();
        $months = array();

        // sync commission into a sheet for the $current_month
        foreach ($commissions as $commission) {

            // get the current month of the current commission eg. 'April', 'August'
            $current_month = date('F', strtotime($commission['created_at']));

            if (!array_key_exists($current_month, $months)) {
                $months[$current_month] = array();
            }

            array_push($months[$current_month], $commission);
        }

        // create a new sheet if this $current_month's sheet doesn't exist from with $months array of commissions
        foreach ($months as $month => $commissions) {

            // string value of months e.g "July", "August"...
            foreach (array_keys($months) as $strMonth) {
                if (!in_array($strMonth, $this->_tabs)) {
                    $this->_GoogleClient->createNewTab($strMonth);

                    // add the new tab to the array of tabs
                    array_push($this->_tabs, $strMonth);

                    // Set the headers for the $strMonth sheet
                    $this->setSheetHeaders($strMonth, $commissions[0]);
                }
            }

            // sync retrieved commission into $current_month google sheet
            $this->setSheetData($month, $commissions);


        }
    }

    public function setSheetHeaders(string $sheetName, array $data)
    {
        $headers = array_keys($data);
        $this->_GoogleClient->appendHeadersRow($sheetName, $headers);
    }

    public function setSheetData(string $sheetName, array $data)
    {
        $deKeyedData = array();

        foreach ($data as $key => $array) {
            $deKeyedData[$key] = array_values($array);
        }

        $valueRange[] = $this->_GoogleClient->setSheetValueRange($sheetName, $deKeyedData);
        $body = $this->_GoogleClient->batchRequest($valueRange);
        $this->_GoogleClient->batchUpdate($body);
    }

    public function getCommissions()
    {
        $commissions = array();
        $page = 1;

        do {
            // ATTN: Get Ambassador doesn't accept date timestamp when querying 'All Commissions', only date('Y-m-d')
            $data = http_build_query(['created_at__gte' => '2017-08-01', 'created_at__lte' => '2018-01-01', 'page' => $page]);
            $response = $this->doCommissionsCurl($data);

            foreach ($response as $commission) {
                $commission = (array)$commission;
                foreach ($commission as $key => $value) {
                    switch (gettype($value)) {
                        case 'array':
                            $commission[$key] = json_encode($value);
                            break;
                        case 'NULL':
                            $commission[$key] = '';
                            break;
                        default:
                            break;
                    }
                }

                array_push($commissions, $commission);
            }

            $count = count($response);
            $page++;
        } while ($count == 100);

        return $commissions;
    }

    public function getContestEntrants()
    {
        $entrants = array();
        $page = 1;

        do {
            $cm = new CS_REST_Lists(getenv('CM_LIST_ID'), ['api_key' => getenv('CM_API_KEY')]);
            $result = $cm->get_active_subscribers('2017-08-01 00:00:00', $page, 1000, 'date', 'asc');

            if (!count($result->response) > 0) {
                echo "Error - No entrants found.\n";
                die();
            }

            $results = $result->response->Results;
            $pages = $result->response->NumberOfPages;
            $intTotalNumberOfRecords = $result->response->TotalNumberOfRecords;

            foreach ($results as $entrant) {
                $entrant = (array)$entrant;
                foreach ($entrant as $key => $value) {
                    switch (gettype($value)) {
                        case 'array':
                            $entrant[$key] = json_encode($value);
                            break;
                        case 'NULL':
                            $entrant[$key] = '';
                            break;
                        default:
                            break;
                    }
                }

                array_push($entrants, $entrant);
            }

            $page++;
        } while ($page <= $pages);


        if ($intTotalNumberOfRecords != count($entrants)) {
            echo "Not all records were extracted.\n";
            die();
        }

        return $entrants;
    }

    private function doCommissionsCurl(string $data)
    {

        error_reporting(E_ALL);
        ini_set("display_errors", 1);

        $response_type = 'json';

        $curl_handle = curl_init();
        $url = 'https://getambassador.com/api/v2/' . getenv('GET_AMBASSADOR_USERNAME') . '/' . getenv('GET_AMBASSADOR_KEY') . '/' . $response_type . '/commission/all/';
        curl_setopt($curl_handle, CURLOPT_URL, $url);
        curl_setopt($curl_handle, CURLOPT_CONNECTTIMEOUT, 10);
        curl_setopt($curl_handle, CURLOPT_RETURNTRANSFER, TRUE);
        curl_setopt($curl_handle, CURLOPT_FOLLOWLOCATION, TRUE);
        curl_setopt($curl_handle, CURLOPT_POST, TRUE);
        curl_setopt($curl_handle, CURLOPT_POSTFIELDS, $data);
        curl_setopt($curl_handle, CURLOPT_FAILONERROR, FALSE);
        curl_setopt($curl_handle, CURLOPT_SSL_VERIFYPEER, FALSE);
        curl_setopt($curl_handle, CURLOPT_SSL_VERIFYHOST, FALSE);
        $response = curl_exec($curl_handle);
        curl_close($curl_handle);

        if (strtolower($response_type) == 'json') {
            // Decode response to array
            $response = json_decode($response, TRUE);

            if (isset($response['response']) && $response['response']['code'] == '200') {
                if (isset($response['response']['data']['commissions'])) {
                    return $response['response']['data']['commissions'];
                } else {
                    var_dump($response);
                    echo "Error, No commissions returned.\n";
                    die();
                }
            } else {
                var_dump($response);
                echo "Error, Unsuccessful Commissions Request.\n";
                die();
            }
        }
    }
}