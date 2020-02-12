<?php

namespace Icinga\Module\Netboximport;

class Api {
    function __construct($baseurl, $apitoken) {
        $this->baseurl = rtrim($baseurl, '/') . '/';
        $this->apitoken = $apitoken;
        $this->cache = [];
    }

    private static function startsWith($haystack, $needle) {
         return (substr($haystack, 0, strlen($needle)) === $needle);
    }

    private function apiRequest($method, $url, $get_params) {
        if ($this->startsWith($url, $this->baseurl)) {
            $url = substr($url, strlen($this->baseurl));
        } else if ($this->startsWith(preg_replace("/^http:/i", "https:", $url), $this->baseurl)) {
            $url = substr($url, strlen($this->baseurl)-1);
        } else if ($this->startsWith(preg_replace("/^https:/i", "http:", $url), $this->baseurl)) {
            $url = substr($url, strlen($this->baseurl)+1);
        }

        $ch = curl_init();

        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);

        $get_params['limit'] = 1000000;

        $query = http_build_query($get_params);
        curl_setopt($ch, CURLOPT_URL, $this->baseurl . trim($url, '/') . '/?' . $query);

        curl_setopt($ch, CURLOPT_HTTPHEADER, array(
            'Authorization: Token ' . $this->apitoken,
        ));

        if($method == 'POST') {
            curl_setopt($ch, CURLOPT_POST, 1);
        } elseif ($method == 'PUT') {
            curl_setopt($ch, CURLOPT_PUT, 1);
        } else {
            // defaults to GET
        }

        $response = curl_exec($ch);
        $curlerror = curl_error($ch);
        $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);

        if ($curlerror === '' && $status === 200) {
            $response = json_decode($response);

            if(isset($response->results)) {
                return $response->results; // collection
            } else {
                return $response; // single
            }
        } else {
            throw new \Exception("Netbox API request failed: status=$status, error=$curlerror");
        }
    }

    public function get($resource, $filter=array(), $cache=TRUE) {
        $cache_key = sha1($resource . json_encode($filter));

        if (isset($this->cache[$cache_key])) {
            return $this->cache[$cache_key];
        }

        $data = $this->apiRequest('GET', $resource, $filter);
        $this->cache[$cache_key] = $data;

        return $data;
    }
}
