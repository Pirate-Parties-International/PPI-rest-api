<?php
namespace AppBundle\Service;

use Symfony\Component\DependencyInjection\Container;

class PopulationService
{
    private   $container;
    protected $em;
    protected $log;
    protected $connect;

    public function __construct(Container $container) {
        $this->container = $container;
        $this->em        = $this->container->get('doctrine')->getManager();
        $this->log       = $this->container->get('logger');
        $this->connect   = $this->container->get('ConnectionService');
        @set_exception_handler([$this->connect, 'exception_handler']);
    }


    /**
     * Returns population data for a party, if available
     * @param  string $partyCode
     * @return int
     */
    public function getPopulation($partyCode) {
        $filePath = $this->getFilePath();

        if (!file_exists($filePath)) {
            $this->log->warning("Population data missing");
            return null;
        }

        $json  = file_get_contents($filePath);
        $array = json_decode($json, true);

        if (!isset($array[$partyCode])) {
            $this->log->warning("Population data missing for " . $partyCode);
            return null;
        }

        return $array[$partyCode];
    }


    /**
     * Retrieves population stats from api.population.io and saves to a json file
     * @return null
     */
    public function getPopulationData() {
        $current = $this->checkLocalData();
        if ($current) {
            return true;
        }

        $this->log->notice("  + Collecting new data...");
        $parties = $this->em->getRepository('AppBundle:Party')->findAll();
        $temp['date'] = date('Y-m-d');

        foreach ($parties as $party) {
            $country = $this->getCountryName($party->getCountryName());

            $url  = "http://api.population.io/1.0/population/" . $country . "/" . $temp['date'] . "/";
            $json = $this->connect->curl($url);
            $arr  = json_decode($json, true);

            if (!isset($arr['total_population'])) {
                $this->log->warning("   - Population data not found for " . $country);
                continue;
            }

            $data = $arr['total_population']['population'];
            $this->log->debug("     + Population for " . $party->getCode() . " = " . $data);

            $temp[$party->getCode()] = $data;
        }

        $this->log->info("    + Saving new data to file");
        $out = json_encode($temp);
        $filePath = $this->getFilePath();
        file_put_contents($filePath, $out);
        $this->log->notice("# Done");
    }


    /**
     * Alters and formats country names to be compatible with api.population.io
     * @param  string $name
     * @return string
     */
    public function getCountryName($name) {
        switch ($name) {
            case 'Bosnia & Herzegovina';
                $out = 'Bosnia and Herzegovina';
                break;
            case 'Catalonia':
                $out = 'Spain';
                break;
            case 'Italia';
                $out = 'Italy';
                break;
            case 'Netherlands';
                $out = 'The Netherlands';
                break;
            case 'Russia':
                $out = 'Russian Federation';
                break;
            case 'Slovakia':
                $out = 'Slovak Republic';
                break;
            case 'Venezuela':
                $out = 'RB-de-Venezuela';
                break;
            default:
                $out = $name;
        }

        return str_replace(' ', '%20', $out);
    }


    /**
     * Checks local files to see if population data has already been collected recently
     * @param  string $filePath
     * @return bool
     */
    public function checkLocalData() {
        $filePath = $this->getFilePath();

        if (!file_exists($filePath)) {
            $this->log->notice("- Population data does not exist");
            return false;
        }

        $json  = file_get_contents($filePath);
        $array = json_decode($json, true);

        $timeLimit = strtotime('-1 month');
        $timeCheck = date('Y-m-d', $timeLimit);

        if (!isset($array['date'])) {
            $this->log->notice("- Population data missing");
            return false;
        }

        $this->log->debug("   + Population data collected on " . $array['date']);

        if ($array['date'] < $timeCheck) {
            $this->log->notice("- Population data is out of date");
            return false;
        }

        $this->log->notice("+ Population data is up-to-date");
        return $filePath;
    }


    /**
     * Returns path to local json file where population data is stored
     * @return string
     */
    public function getFilePath() {
        $appRoot  = $this->container->get('kernel')->getRootDir() . '/..';
        $filePath = $appRoot . '/etc/population.json';

        return $filePath;
    }

}