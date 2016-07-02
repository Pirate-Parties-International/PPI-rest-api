<?php
namespace AppBundle\Command;

use Symfony\Bundle\FrameworkBundle\Command\ContainerAwareCommand;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

use Pirates\PapiInfo\Compile;

use AppBundle\Entity\IntOrgMembership;
use AppBundle\Entity\Party;
use AppBundle\Entity\InternationalOrg as IntOrg;

class LoadDataCommand extends ContainerAwareCommand
{
    protected function configure()
    {
        $this
            ->setName('papi:loadData')
            ->setDescription('Load JSON data into the database. Will overwrite!')
        ;
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $this->output = $output;
        $this->logger = $this->getContainer()->get('logger');
        $this->container = $this->getContainer();
        $em = $this->container->get('doctrine')->getManager();

        //
        // First, let's clear the DB
        // 

        $this->log("## Clearing DB.");

        $cmd = $em->getClassMetadata('AppBundle\Entity\IntOrgMembership');
        $connection = $em->getConnection();
        $dbPlatform = $connection->getDatabasePlatform();
        $connection->query('SET FOREIGN_KEY_CHECKS=0');
        $q = $dbPlatform->getTruncateTableSql($cmd->getTableName());
        $connection->executeUpdate($q);
        $connection->query('SET FOREIGN_KEY_CHECKS=1');

        $cmd = $em->getClassMetadata('AppBundle\Entity\Party');
        $connection = $em->getConnection();
        $dbPlatform = $connection->getDatabasePlatform();
        $connection->query('SET FOREIGN_KEY_CHECKS=0');
        $q = $dbPlatform->getTruncateTableSql($cmd->getTableName());
        $connection->executeUpdate($q);
        $connection->query('SET FOREIGN_KEY_CHECKS=1');
        
        $this->log("Done clearing.");

        //
        // Now, let's load in the data
        // 

        $this->log("## Starting data import");
        
        $appRoot = $this->container->get('kernel')->getRootDir() . '/..';

        $intOrg['ppeu'] = $this->container->get('doctrine')
        ->getRepository('AppBundle:InternationalOrg')
        ->findOneByCode('ppeu');

        $intOrg['ppi'] = $this->container->get('doctrine')
        ->getRepository('AppBundle:InternationalOrg')
        ->findOneByCode('ppi');

        $intOrg['ype'] = $this->container->get('doctrine')
        ->getRepository('AppBundle:InternationalOrg')
        ->findOneByCode('ype');

        if (!$intOrg['ppeu'] || !$intOrg['ppi'] || !$intOrg['ype']) {
            $this->log("Int orgs are missing!");
            return;
        }
        
        $compile = new Compile;
        $data = $compile->getAllData();

        $this->log("Data retrived.");

        //
        // Let's get logo files and build an array
        //

        $this->log("## Prepairing logos");

        $logoDir = $appRoot . '/web/img/pp-logo/';
        if (!is_dir($logoDir)) {
            mkdir($logoDir, 0755, true);
        }

        $logos = $compile->getLogoFiles();

        $logoFiles = [];
        foreach ($logos as $logoKey => $logoPath) {
            preg_match('/.+\/(([a-zA-Z-]+)\.(png|jpg))$/i', $logoPath, $matches);
            $filename = $matches[1];
            copy($logoPath, $logoDir . $filename);
            $logoFiles['pp' . strtolower($logoKey)] = '/img/pp-logo/' . $filename;
        }

        $this->log("Done with ".count($logoFiles)." logos");

        //
        // Now we build flag files into an array
        // 
        $this->log("## Processing flags");

        $flagDir = $appRoot . '/web/img/pp-flag/';
        if (!is_dir($flagDir)) {
            mkdir($flagDir, 0755, true);
        }

        $flags = $compile->getFlagFiles();

        $flagFiles = [];
        foreach ($flags as $flagKey => $flagPath) {
            preg_match('/.+\/(([a-zA-Z-]+)\.(png|jpg))$/i', $flagPath, $matches);
            $filename = $matches[1];
            copy($flagPath, $flagDir . $filename);
            $flagFiles[strtolower($flagKey)] = '/img/pp-flag/' . $filename;
        }

        $this->log("Done with ".count($flagFiles)." flags");

        //
        // Party data import
        //

        $this->log(sprintf("## Processing meta data for %s organisations.", count($data)));

        $genericLogo = '/img/generic.png';
        $genericFlag = '/img/genericFlag.png';

        foreach ($data as $partyKey => $partyData) {
        	$this->log("    - Processing " . $partyKey);

            $party = new Party();
            $party->setCode($partyData->partyCode);
            $party->setCountryCode($partyData->countryCode);
            $party->setCountryName($partyData->country);
            $party->setName($partyData->partyName);
            $party->setType($partyData->type);

            if (!empty($partyData->defunct) && $partyData->defunct == true) {
                $party->setDefunct(true);
            }
            if (!empty($partyData->region)) {
                $party->setRegion($partyData->region);
            }
            if (!empty($partyData->parentorganisation)) {
                $party->setParentParty($partyData->parentorganisation);
            }

            if (!empty($partyData->headquarters)) {
                $party->setHeadquarters($partyData->headquarters);
            }

            if (!empty($partyData->websites)) {
                $party->setWebsites($partyData->websites);
            }

            if (!empty($partyData->socialNetworks)) {
                $party->setSocialNetworks($partyData->socialNetworks);
            }

            if (!empty($partyData->contact)) {
                $party->setContact($partyData->contact);
            }

            if (isset($logoFiles[strtolower($party->getCode())])) {
                $party->setLogo($logoFiles[strtolower($party->getCode())]);
            } else {
                $party->setLogo($genericLogo);
            }

            if (isset($flagFiles[strtolower($party->getCountryCode())])) {
                $party->setCountryFlag($flagFiles[strtolower($party->getCountryCode())]);
            } else {
                $party->setCountryFlag($genericFlag);
            }
            if (isset($partyData->membership)) {
                foreach ($partyData->membership as $key => $value) {
                    if ($value !== false && is_string($value)) {
                        $intMem = new IntOrgMembership();
                        $intMem->setType($value);
                        $intMem->setParty($party);
                        $intMem->setIntOrg($intOrg[$key]);
                        $party->addIntMembership($intMem);

                    }
                }
            }

            $em->persist($party);
            $em->flush();

        }

        $this->log("Done with orgs.");

        

        

        $this->log("Done.");
        
    }

    protected function log($msg) {
        $this->logger->info($msg);
        $this->output->writeln($msg);
    }
}