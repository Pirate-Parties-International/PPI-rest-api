<?php
namespace PPI\ApiBundle\Command;

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
            ->addArgument('file', InputArgument::REQUIRED, 'File to import')
        ;
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $this->output = $output;
        $this->logger = $this->getContainer()->get('logger');
        $this->container = $this->getContainer();

        $file = $input->getArgument('file');

        $this->log("Starting data import");
        $data = file_get_contents($file);
        if ($data === false) {
        	$this->log("Could not get file contents");
        	return;
        }
        if (empty($data)) {
        	$this->log("File is empty");
        	return;
        }
        $data = json_decode($data);
        if ($data === null) {
        	$this->log("Data could not be decoded from JSON");
        	return;
        }

        $this->log("Data retrived sucessfully");

        $redis = $this->container->get('snc_redis.default');

        $this->log(sprintf("Processing %s organisations.", count($data)));

        foreach ($data as $partyKey => $partyData) {
        	$this->log("    - Processing " . $partyKey);
        	if (isset($partyData->partyName->en)) {
        		if (preg_match('/(.+) \((.+)\)/i', $partyData->partyName->en, $matches) === 1) {
        			$partyData->partyName->en = $matches[1];
        			$partyData->partyCode = $matches[2];
        		}
        	}
        	$redis->set('ppi:orgs:' . $partyKey, json_encode($partyData));
        }

        $this->log("Done.");

        
    }

    protected function log($msg) {
        $this->logger->info($msg);
        $this->output->writeln($msg);
    }
}