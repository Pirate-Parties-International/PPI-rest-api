<?php
namespace AppBundle\Command;

use Symfony\Bundle\FrameworkBundle\Command\ContainerAwareCommand;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

class LoadDataCommand extends ContainerAwareCommand
{
    protected function configure()
    {
        $this
            ->setName('ppi:api:loadData')
            ->setDescription('Load JSON data into redis')
        ;
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $this->output = $output;
        $this->logger = $this->getContainer()->get('logger');
        $this->container = $this->getContainer();

        $appRoot = $this->container->get('kernel')->getRootDir() . '/..';

        $this->log("Starting data import");
        define('INCLUDE_RETURN_DATA', true);
        $data = include 'data/compile.php';

        $this->log("Data retrived sucessfully");

        $redis = $this->container->get('snc_redis.default');

        $this->log(sprintf("Processing meta data for %s organisations.", count($data)));

        foreach ($data['data'] as $partyKey => $partyData) {
        	$this->log("    - Processing " . $partyKey);
            $partyData->lastUpdate = date('c');
        	$redis->set('ppi:orgs:' . strtolower($partyKey), json_encode($partyData));
        }

        $this->log("Done with orgs.");

        $this->log("Processing logos");

        $logoDir = $appRoot . '/web/img/pp-logo/';
        if (!is_dir($logoDir)) {
            mkdir($logoDir, 0755, true);
        }

        foreach ($data['logo'] as $logoKey => $logoPath) {
            preg_match('/.+\/(([a-zA-Z-]+)\.(png|jpg))$/i', $logoPath, $matches);
            $filename = $matches[1];
            copy($logoPath, $logoDir . $filename);
            $redis->set('ppi:logos:' . strtolower($logoKey), '/img/pp-logo/' . $filename);
        }

        $this->log("Processing flags");

        $flagDir = $appRoot . '/web/img/pp-flag/';
        if (!is_dir($flagDir)) {
            mkdir($flagDir, 0755, true);
        }

        foreach ($data['flag'] as $flagKey => $flagPath) {
            preg_match('/.+\/(([a-zA-Z-]+)\.(png|jpg))$/i', $flagPath, $matches);
            $filename = $matches[1];
            copy($flagPath, $flagDir . $filename);
            $redis->set('ppi:flags:' . strtolower($flagKey), '/img/pp-flag/' . $filename);
        }

        $this->log("Done.");
        
    }

    protected function log($msg) {
        $this->logger->info($msg);
        $this->output->writeln($msg);
    }
}