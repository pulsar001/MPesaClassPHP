<?php

class MpesaApi {

    public $bddp; 
    public $conf; // index
    public $data; // file_get_contents("php://input") : index

    private $return; // retour


    private $test_mode; // true / false
    public $date_format = "YmdHis"; // timestamp format

    private $ACCESS_TOKEN; // token connexion
    private $PASSWORD64; // base64 password (shortcode.passkey.timestamp)
    public $TIMESTAMP; // Heure

    private $SHORT_CODE; // Credentials
    private $PASS_KEY; // Credentials
    private $CERT; // Certificat crt
    private $URL_API; // Url api 

    function __construct($data, $conf) {
        
        // body option: raw
        $this->data = json_decode($data);

        // configuration
        $this->conf = $conf;
        
        // var
        $this->SHORT_CODE = $this->conf->credentials->short_code;
        $this->PASS_KEY = $this->conf->credentials->pass_key;

        $this->test_mode = true;

        if($this->test_mode === true) 
        {
            $this->CERT = "file://".$this->conf->cert->path_sandbox;
            $this->URL_API = "https://sandbox.safaricom.co.ke";
        }
        else
        {
            $this->CERT = "file://".$this->conf->cert->path_public;
            $this->URL_API = "https://api.safaricom.co.ke";
        }

    }

    // début de la session
    private function start_session() {
        session_start();
    }

    // connexion ddb
    private function connect_db() {

        $this->bddp = new PDO(
            "mysql:host=".$this->conf->sql->host.";
            port=".$this->conf->sql->port.";
            dbname=".$this->conf->sql->dbname,
            $this->conf->sql->user,
            $this->conf->sql->pass
        );

        $this->bddp->exec('SET NAMES utf8');
        $this->bddp->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->bddp->setAttribute(PDO::ATTR_AUTOCOMMIT,0);
        $this->bddp->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);

    }

    // affichage en format json
    public function json($string) {
        return json_encode($string, JSON_PRETTY_PRINT);
    }

    // format data en objet
    public function format_data($data) {
        return json_decode($data, false);
    }

    // signer une transaction
    public function sign($plaintext) {
        openssl_public_encrypt($plaintext, $encrypted, $this->CERT, OPENSSL_PKCS1_PADDING);
        return base64_encode($encrypted);
    }

    // mot de passe
    public function password() {
        return base64_encode($this->SHORT_CODE.$this->PASS_KEY.$this->TIMESTAMP);
    }
    
    // connexion API
    public function connect_api() {

        if(!$this->data->APP_CONSUMER_KEY && !$this->data->APP_CONSUMER_SECRET) 
            return ["type" => "error", "response" => "ERROR #2EJ94H: CONNECT API"];

        
        $url = $this->URL_API.'/oauth/v1/generate?grant_type=client_credentials';
  
        $curl = curl_init();
        curl_setopt($curl, CURLOPT_URL, $url);
        $credentials = base64_encode($this->data->APP_CONSUMER_KEY.':'.$this->data->APP_CONSUMER_SECRET);
        curl_setopt($curl, CURLOPT_HTTPHEADER, array('Authorization: Basic '.$credentials)); //setting a custom header
        curl_setopt($curl, CURLOPT_HEADER, false);
        curl_setopt($curl, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($curl, CURLOPT_RETURNTRANSFER, true);

        
        $curl_response = curl_exec($curl);
        $response = json_decode($curl_response, true);
        $info = curl_getinfo($curl);
        
        // error
        if (curl_errno($curl) || $curl_response === false)
            return ["type" => "error", "code" => $info['http_code'], "response" => "ERROR #33J94H: CONNECT API ERR"];
        

        curl_close($curl);
        
        // pas de http code ni access token
        if(!isset($info['http_code']) || $info['http_code'] != "200" || !isset($response) || empty($response['access_token']))
            return ["type" => "error", "code" => $info['http_code'], "response" => "ERROR #33E6DE: CONNECT API"];

        // sauvegarder access token
        $this->ACCESS_TOKEN = $response['access_token'];

        return ["type" => "success", "code" => $info['http_code'], "response" => $response];

    }

    // creer une commande de base
    public function command($url, $data_tx) {
        
        if(!$url || !$data_tx) 
            return ["type" => "error", "response" => "ERROR #6RE5DE: COMMAND BASE API"];

            
        $curl = curl_init();
        curl_setopt($curl, CURLOPT_URL, $url);
        curl_setopt($curl, CURLOPT_HTTPHEADER, array('Content-Type:application/json','Authorization:Bearer '.$this->ACCESS_TOKEN)); //setting custom header
        $data_string = json_encode($data_tx);
        curl_setopt($curl, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($curl, CURLOPT_POST, true);
        curl_setopt($curl, CURLOPT_POSTFIELDS, $data_string);
        curl_setopt($curl, CURLOPT_HEADER, false);
        curl_setopt($curl, CURLOPT_RETURNTRANSFER, true);
       
        $curl_response = curl_exec($curl);

        if (curl_errno($curl) || $curl_response === false)
            return ["type" => "error", "response" => "ERROR #44J94H: COMMAND API ERR"];
    

        $info = curl_getinfo($curl);

        curl_close($curl);
        
        return ["type" => "success", "code" => $info['http_code'], "response" => json_decode($curl_response, true)];
    }

    // command: voir solde compte
    public function command_AccountBalance() {

        $url = $this->URL_API.'/mpesa/accountbalance/v1/query';

        $data_tx = array(
          'Initiator' => $this->data->Initiator,
          'SecurityCredential' => $this->data->SecurityCredential,
          'CommandID' => 'AccountBalance',
          'PartyA' => $this->data->PartyA,
          'IdentifierType' => '4',
          'Remarks' => 'AccountBalance',
          'QueueTimeOutURL' => 'https://enrjottre2ni.x.pipedream.net/timeout_url',
          'ResultURL' => 'https://enrjottre2ni.x.pipedream.net/result_url'
        );
        
        return $this->command($url, $data_tx);

    }

    // command: voir status transaction
    public function command_TransactionStatusQuery() {

        $url = $this->URL_API.'/mpesa/transactionstatus/v1/query';

        $data_tx = array(
            'Initiator' => ' ',
            'SecurityCredential' => ' ',
            'CommandID' => 'TransactionStatusQuery',
            'TransactionID' => ' ',
            'PartyA' => ' ',
            'IdentifierType' => '1',
            'ResultURL' => 'https://ip_address:port/result_url',
            'QueueTimeOutURL' => 'https://ip_address:port/timeout_url',
            'Remarks' => ' ',
            'Occasion' => ' '
        );
        
        return $this->command($url, $data_tx);

    }

    // command: inverser transaction
    public function command_TransactionReversal() {

        $url = $this->URL_API.'/mpesa/reversal/v1/request';

        $data_tx = array(
            'Initiator' => ' ',
            'SecurityCredential' => ' ',
            'CommandID' => 'TransactionReversal',
            'TransactionID' => ' ',
            'Amount' => ' ',
            'ReceiverParty' => ' ',
            'RecieverIdentifierType' => '4',
            'ResultURL' => 'https://ip_address:port/result_url',
            'QueueTimeOutURL' => 'https://ip_address:port/timeout_url',
            'Remarks' => ' ',
            'Occasion' => ' '
        );
        
        return $this->command($url, $data_tx);

    }

    // command: payer facture
    public function command_CustomerPayBillOnline() {

        $url = $this->URL_API.'/mpesa/stkpush/v1/processrequest';

        $data_tx = array(
            'BusinessShortCode' => $this->SHORT_CODE,
            'Password' => $this->password(),
            'Timestamp' => $this->TIMESTAMP,
            'TransactionType' => 'CustomerPayBillOnline',
            'Amount' => $this->data->Amount,
            'PartyA' => $this->data->PartyA,
            'PartyB' => $this->data->PartyB,
            'PhoneNumber' => $this->data->PhoneNumber,
            'CallBackURL' => 'https://enrjottre2ni.x.pipedream.net/callback',
            'AccountReference' => $this->data->AccountReference,
            'TransactionDesc' => $this->data->TransactionDesc
        );
        
        return $this->command($url, $data_tx);

    }


}


 

