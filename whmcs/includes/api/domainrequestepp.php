<?php 
if( !defined("WHMCS") ) 
{
    exit( "This file cannot be accessed directly" );
}

if( !function_exists("RegGetEPPCode") ) 
{
    require(ROOTDIR . "/includes/registrarfunctions.php");
}

$result = select_query("tbldomains", "id,domain,registrar,registrationperiod", array( "id" => $domainid ));
$data = mysql_fetch_array($result);
$domainid = $data[0];
if( !$domainid ) 
{
    $apiresults = array( "result" => "error", "message" => "Domain ID Not Found" );
    return false;
}

$domain = $data["domain"];
$registrar = $data["registrar"];
$regperiod = $data["registrationperiod"];
$domainparts = explode(".", $domain, 2);
$params = array(  );
$params["domainid"] = $domainid;
list($params["sld"], $params["tld"]) = $domainparts;
$params["regperiod"] = $regperiod;
$params["registrar"] = $registrar;
$values = RegGetEPPCode($params);
if( $values["error"] ) 
{
    $apiresults = array( "result" => "error", "message" => "Registrar Error Message", "error" => $values["error"] );
    return false;
}

$apiresults = array_merge(array( "result" => "success" ), $values);

