<?php

namespace Icinga\Module\Netboximport;

class Api
{
    public function __construct($baseurl, $apitoken)
    {
        $this->baseurl = rtrim($baseurl, '/') . '/';
        $this->apitoken = $apitoken;
        $this->cache = [];
    }

    // private static function startsWith($haystack, $needle) {
    //      return (substr($haystack, 0, strlen($needle)) === $needle);
    // }

    private function setupCurl()
    {
        $ch = curl_init();

        // Configure curl
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true); // curl_exec returns response as a string
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true); // Follow redirect requests
        curl_setopt($ch, CURLOPT_MAXREDIRS, 5); // limit number of redirects to follow

        curl_setopt($ch, CURLOPT_HTTPHEADER, array(
            'Authorization: Token ' . $this->apitoken,
        ));

        return $ch;
    }

    // private function apiRequest($method, $url, $get_params, $ch = null)
    private function apiGet($url, $get_params = [], $ch = null)
    {
        // if ($this->startsWith($url, $this->baseurl)) {
        //     $url = substr($url, strlen($this->baseurl));
        // } else if ($this->startsWith(preg_replace("/^http:/i", "https:", $url), $this->baseurl)) {
        //     $url = substr($url, strlen($this->baseurl)-1);
        // } else if ($this->startsWith(preg_replace("/^https:/i", "http:", $url), $this->baseurl)) {
        //     $url = substr($url, strlen($this->baseurl)+1);
        // }

        //  This module should only ever pull information from netbox, right?
        // if($method == 'POST') {
        //     curl_setopt($ch, CURLOPT_POST, 1);
        // } elseif ($method == 'PUT') {
        //     curl_setopt($ch, CURLOPT_PUT, 1);
        // } else {
        //     // defaults to GET
        // }

        // Create curl object if necessary
        if (!isset($ch)) {
            $ch = $this->setupCurl();
        }

        $url_path = parse_url($url, PHP_URL_PATH);

        // Return empty object if path is blank
        if (trim($url_path, '/') === '') {
            return [];
        }

        // Strip '/api' since it's included in $this->baseurl
        $url_path = preg_replace("#^/api/#", "/", $url_path);

        // This is limited by MAX_PAGE_SIZE (https://netbox.readthedocs.io/en/stable/configuration/optional-settings/#max_page_size)
        $get_params['limit'] = 1001;

        // Convert parameters to URL-encoded query string
        $query = http_build_query($get_params);

        // Tie it all together
        $uri = $this->baseurl . trim($url_path, '/') . '/?' . $query;

        curl_setopt($ch, CURLOPT_URL, $uri);

        $response = curl_exec($ch); // CURLOPT_RETURNTRANSFER makes this a string
        $curlerror = curl_error($ch);
        $status = curl_getinfo($ch, CURLINFO_RESPONSE_CODE);

        if ($curlerror === '' && $status === 200) {
            $response = json_decode($response);

            return $response;
        // if (!isset($response->results)) { // single object
            //     return $response;
            // } elseif (isset($response->next)) { // paginated results
            //     // more results
            //     // array_merge($response->results, apiRequest($method, $url, $get_params, $ch)); // recursion
            //     $all_results = array_merge(
            //     $response->results,
            //     $this->apiGet($response->next, $get_params, $ch)
            //   );
            //     return $all_results;
            // } elseif (!isset($response->next)) { // end of pagination or single page
            //     return $response->results;
            // }

            // if(isset($response->results)) {
            //     return $response->results; // collection
            // } else {
            //     return $response; // single
            // }
        } else {
            throw new \Exception("Netbox API request failed: uri=$uri; status=$status; error=$curlerror");
        }
    }

    // Query API for resource passed
    //    $resource(String) - API Path
    // public function getResource($resource, $filter=array(), $cache=true)
    public function getResource($resource, $follow_pagination = false)
    {
        // $cache_key = sha1($resource . json_encode($filter));
        //
        // if (isset($this->cache[$cache_key])) {
        //     return $this->cache[$cache_key];
        // }

        if ($follow_pagination === true) {
            // loop over resource until "next" field is null
            $data = $this->apiGet($resource);
        } else {
            $data = $this->apiGet($resource);
        }

        // $this->cache[$cache_key] = $data;

        return $data;
    }
}
