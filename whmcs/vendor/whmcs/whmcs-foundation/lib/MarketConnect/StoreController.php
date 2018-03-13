<?php 
namespace WHMCS\MarketConnect;


class StoreController
{
    public function order(\WHMCS\Http\Message\ServerRequest $request)
    {
        $ca = new \WHMCS\ClientArea();
        $ca->setPageTitle(\Lang::trans("store.configure.configureProduct") . " - " . \Lang::trans("navStore"));
        $ca->addToBreadCrumb("index.php", \Lang::trans("globalsystemname"));
        $ca->addToBreadCrumb(routePath("store"), \Lang::trans("navStore"));
        $ca->addToBreadCrumb("#", \Lang::trans("store.configure.configureProduct"));
        $ca->initPage();
        $sessionCurrency = \WHMCS\Session::get("currency");
        $currency = getCurrency($ca->getUserId(), $sessionCurrency);
        $ca->assign("activeCurrency", $currency);
        $pid = $request->request()->get("pid");
        $serviceId = $request->request()->get("serviceid");
        $productKey = $request->request()->get("productkey");
        if( $productKey && !$pid ) 
        {
            $pid = Product::where("servertype", "marketconnect")->where("configoption1", $productKey)->pluck("id")->first();
        }

        $requestBillingCycle = $request->request()->get("billingcycle", "");
        if( $requestBillingCycle ) 
        {
            \WHMCS\Session::set("storeBillingCycle", $requestBillingCycle);
        }

        $ca->assign("requestedCycle", \WHMCS\Session::get("storeBillingCycle"));
        if( $pid ) 
        {
            \WHMCS\Session::set("storePid", $pid);
        }

        $pid = \WHMCS\Session::get("storePid");
        if( !$pid ) 
        {
            return new \Zend\Diactoros\Response\RedirectResponse(\WHMCS\Utility\Environment\WebHelper::getBaseUrl() . "/cart.php");
        }

        $product = Product::findOrFail($pid);
        $product->pricing($currency);
        $ca->assign("product", $product);
        $promotionalHelper = MarketConnect::factoryPromotionalHelperByProductKey($product->productKey);
        if( !$promotionalHelper ) 
        {
            return new \Zend\Diactoros\Response\RedirectResponse(\WHMCS\Utility\Environment\WebHelper::getBaseUrl() . "/cart.php");
        }

        $upsellProduct = $promotionalHelper->getBestUpsell($product->productKey);
        if( !is_null($upsellProduct) ) 
        {
            $upsellProduct->pricing($currency);
            $upsellComparison = new \WHMCS\Product\Pricing\Comparison($upsellProduct->pricing($currency), $product->pricing($currency), $currency);
        }

        $ca->assign("upsellProduct", $upsellProduct);
        $ca->assign("upsellComparison", $upsellComparison);
        $ca->assign("promotion", $promotionalHelper->getUpsellPromotionalContent($upsellProduct->productKey));
        $domains = \WHMCS\Service\Service::where("userid", $ca->getUserId())->where("domain", "!=", "")->where("domainstatus", "Active")->pluck("domain");
        $domainRegistrations = \WHMCS\Domain\Domain::where("userid", $ca->getUserId())->where("domain", "!=", "")->where("status", "Active")->pluck("domain");
        $ca->assign("domains", $domains->merge($domainRegistrations)->unique());
        $sslTypes = array( "rapidssl", "geotrust", "symantec" );
        $productType = strtolower(explode("_", $product->productKey)[0]);
        $allowSubdomains = $ca->isLoggedIn() && in_array($productType, $sslTypes);
        $ca->assign("allowSubdomains", $allowSubdomains);
        $selectedDomain = \WHMCS\Session::get("storeSelectedDomain");
        if( $serviceId ) 
        {
            try
            {
                $selectedDomain = \WHMCS\Service\Service::findOrFail($serviceId)->domain;
                if( $serviceId ) 
                {
                    \WHMCS\Session::set("storeSelectedDomain", $selectedDomain);
                }

            }
            catch( \Exception $e ) 
            {
            }
        }

        $ca->assign("selectedDomain", $selectedDomain);
        $ca->setTemplate("store/order");
        $ca->skipMainBodyContainer();
        return $ca;
    }

    public function login(\WHMCS\Http\Message\ServerRequest $request)
    {
        $ca = $this->order($request);
        if( $ca->isLoggedIn() ) 
        {
            return new \Zend\Diactoros\Response\RedirectResponse(routePath("store-order"));
        }

        $ca->requireLogin();
    }

    public function addToCart(\WHMCS\Http\Message\ServerRequest $request)
    {
        check_token("WHMCS.default");
        $redirectPath = "";
        $pid = $request->request()->get("pid");
        $billingcycle = $request->request()->get("billingcycle");
        $domain_type = $request->request()->get("domain_type");
        $existing_domain = $request->request()->get("existing_domain");
        $sub_domain = $request->request()->get("sub_domain");
        $existing_sld_for_subdomain = $request->request()->get("existing_sld_for_subdomain");
        $custom_domain = $request->request()->get("custom_domain");
        $continue = $request->request()->get("continue");
        $checkout = $request->request()->get("checkout");
        $ca = new \WHMCS\ClientArea();
        $product = Product::findOrFail($pid);
        $configOption1 = $product->moduleConfigOption1;
        $addAsProduct = false;
        $addAsAddon = false;
        $addonParentId = null;
        $domain = null;
        if( $domain_type == "existing-domain" && $existing_domain ) 
        {
            $domains = \WHMCS\Service\Service::where("userid", $ca->getUserId())->where("domain", "!=", "")->where("domainstatus", "Active")->pluck("id", "domain");
            $domainRegistrations = \WHMCS\Domain\Domain::where("userid", $ca->getUserId())->where("domain", "!=", "")->where("status", "Active")->pluck("domain");
            if( $domains->has($existing_domain) ) 
            {
                $addAsAddon = true;
                $addonParentId = $domains[$existing_domain];
            }
            else
            {
                if( $domainRegistrations->contains($existing_domain) ) 
                {
                    $addAsProduct = true;
                    $domain = $existing_domain;
                }

            }

        }
        else
        {
            if( $domain_type == "sub-domain" && $sub_domain && $existing_sld_for_subdomain ) 
            {
                $fullDomainName = $sub_domain . "." . $existing_sld_for_subdomain;
                $domains = \WHMCS\Service\Service::where("userid", $ca->getUserId())->where("domain", "!=", "")->where("domainstatus", "Active")->pluck("id", "domain");
                if( $domains->has($fullDomainName) ) 
                {
                    $addAsAddon = true;
                    $addonParentId = $domains[$fullDomainName];
                }
                else
                {
                    $addAsProduct = true;
                    $domain = $fullDomainName;
                }

            }
            else
            {
                if( $custom_domain ) 
                {
                    $addAsProduct = true;
                    $domain = $custom_domain;
                }

            }

        }

        if( !is_null($domain) && !$this->validateDomain($domain) ) 
        {
            return new \Zend\Diactoros\Response\RedirectResponse(routePath("store-order"));
        }

        if( $addAsAddon ) 
        {
            $addonModel = \WHMCS\Config\Module\ModuleConfiguration::with("productAddon")->where("entity_type", "addon")->where("setting_name", "configoption1")->where("value", $configOption1)->get()->where("productAddon.module", "marketconnect")->first();
            if( !is_null($addonModel) ) 
            {
                $addonModel = $addonModel->productAddon;
                \WHMCS\OrderForm::addAddonToCart($addonModel->id, $addonParentId, $billingcycle);
            }

        }
        else
        {
            if( $addAsProduct ) 
            {
                \WHMCS\OrderForm::addProductToCart($product->id, $billingcycle, $domain);
            }
            else
            {
                $redirectPath = routePath("store-order");
            }

        }

        if( !$redirectPath ) 
        {
            if( $checkout ) 
            {
                $redirectPath = \WHMCS\Utility\Environment\WebHelper::getBaseUrl() . "/cart.php?a=view";
            }
            else
            {
                $redirectPath = \WHMCS\Utility\Environment\WebHelper::getBaseUrl() . "/cart.php";
            }

        }

        return new \Zend\Diactoros\Response\RedirectResponse($redirectPath);
    }

    private function validateDomain($domain)
    {
        $domainParts = explode(".", $domain, 2);
        list($sld, $tld) = $domainParts;
        if( count($domainParts) == 2 && $sld != "" && $tld != "" && \WHMCS\Domains\Domain::isValidDomainName($sld, $tld) ) 
        {
            return true;
        }

        return false;
    }

    public function validate(\WHMCS\Http\Message\ServerRequest $request)
    {
        $domain = $request->request()->get("domain");
        $valid = $this->validateDomain($domain);
        return new \WHMCS\Http\Message\JsonResponse(array( "valid" => $valid ));
    }

}


