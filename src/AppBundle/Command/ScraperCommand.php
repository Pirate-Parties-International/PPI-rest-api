<?php
namespace AppBundle\Command;

use Symfony\Bundle\FrameworkBundle\Command\ContainerAwareCommand;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

use AppBundle\Entity\Metadata;
use AppBundle\Entity\Statistic;
use AppBundle\Entity\SocialMedia;

use Pirates\PapiInfo\Compile;

class ScraperCommand extends ContainerAwareCommand
{
    private   $container;
    protected $db;
    protected $em;
    protected $log;
    protected $verify;

    protected $scrapeParty = null;
    protected $scrapeSite  = null;
    protected $scrapeData  = null;
    protected $scrapeStart = null;
    protected $scrapeFull  = false;


    protected function configure()
    {
        $this
            ->setName('papi:scraper')
            ->setDescription('Scrapes FB, TW and G+ data. Should be run once per day.')
            ->addOption('party',  'p', InputOption::VALUE_OPTIONAL, 'Choose a single party to scrape, by code (i.e. ppse, ppsi)')
            ->addOption('site',   'w', InputOption::VALUE_OPTIONAL, 'Choose a single website to scrape (fb, tw, g+ or yt)')
            ->addOption('data',   'd', InputOption::VALUE_OPTIONAL, 'Choose a single data type to scrape, fb only (info, posts, images or events)')
            ->addOption('resume', 'r', InputOption::VALUE_OPTIONAL, 'Choose a point to resume scraping, by party code (e.g. if previously interrupted)')
            ->addOption('full',   'f', InputOption::VALUE_NONE,     'Scrape all data, overwriting db (by default, only posts more recent than the latest db entry are scraped)')
        ;
    }


    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $this->container = $this->getContainer();
        $this->db        = $this->container->get('DatabaseService');
        $this->em        = $this->container->get('doctrine')->getManager();
        $this->log       = $this->container->get('logger');
        $this->verify    = $this->container->get('VerificationService');

        $this->log->notice("##### Starting scraper #####");
        $startTime = new \DateTime('now');

        $options = $this->verify->verifyInput($input);
        $this->scrapeParty = $options['party'];  // if null, get all
        $this->scrapeStart = $options['resume']; // if null, get all
        $this->scrapeSite  = $options['site'];   // if null, get all
        $this->scrapeData  = $options['data'];   // if null, get all
        $this->scrapeFull  = $options['full'];   // if null, get most recent posts only

        if (!$this->scrapeParty) {
            $this->log->info("# Getting all parties...");
            $parties = $this->db->getAllParties();
        } else {
            $this->log->info("# Getting one party (" . $this->scrapeParty . ")...");
            $parties = $this->db->getOneParty($this->scrapeParty);
        }
        $this->log->info("  + Done");

        foreach ($parties as $partyCode => $party) {
            if ($this->scrapeStart && ($partyCode < $this->scrapeStart)) {
                $this->log->info("  - " . $partyCode . " skipped...");
                continue;
            }

            $this->log->notice("# Processing " . $partyCode);

            $socialNetworks = $party->getSocialNetworks();
            if (empty($socialNetworks)) {
                $this->log->warning("- Social Network information missing for " . $partyCode);
                continue;
            }

            $this->processParty($partyCode, $socialNetworks);
        }

        $endDiff = $startTime->diff(new \DateTime('now'));
        $this->log->notice("# All done in " . $endDiff->format('%H:%I:%S'));
    }


    /**
     * Process each party according to input arguments
     * @param string $partyCode
     * @param array  $socialNetworks
     */
    public function processParty($partyCode, $socialNetworks) {
        $midTime = new \DateTime('now');

        switch ($this->scrapeSite) {
            case 'fb':
                $this->scrapeFacebook($partyCode, $socialNetworks);
                break;
            case 'tw':
                $this->scrapeTwitter($partyCode, $socialNetworks);
                break;
            case 'g+':
                $this->scrapeGooglePlus($partyCode, $socialNetworks);
                break;
            case 'yt':
                $this->scrapeYoutube($partyCode, $socialNetworks);
                break;
            default: // case null, scrape all
                $this->scrapeFacebook($partyCode, $socialNetworks);
                $this->scrapeTwitter($partyCode, $socialNetworks);
                $this->scrapeGooglePlus($partyCode, $socialNetworks);
                $this->scrapeYoutube($partyCode, $socialNetworks);
        }

        $this->log->info("  + Saving to DB");
        $this->em->flush();

        $midDiff = $midTime->diff(new \DateTime('now'));
        $this->log->notice("# Done with " . $partyCode . " in " . $midDiff->format('%H:%I:%S'));
    }


    /**
    * FACEBOOK scraping
    * @param string $partyCode
    * @param array  $socialNetworks
    */
    public function scrapeFacebook($partyCode, $socialNetworks)
    {
        if (empty($socialNetworks['facebook']) || empty($socialNetworks['facebook']['username'])) {
            $this->log->warning(" - Facebook data not found for " . $partyCode);
            return false;
        }

        $this->log->info("  + Starting Facebook import");
        $fbData = $this->container->get('FacebookService')
            ->getFBData($partyCode, $socialNetworks['facebook']['username'], $this->scrapeData, $this->scrapeFull);

        if (!$fbData) {
            $this->log->notice("  - ERROR while retrieving FB data for " . $partyCode);
            return false;
        }

        $this->log->info("    + Facebook data retrieved");
        $this->verify->verifyFbData($fbData, $this->scrapeData);
        $this->log->info("  + All Facebook data processed");
    }


    /**
     * TWITTER scraping
     * @param string $partyCode
     * @param array  $socialNetworks
     */
    public function scrapeTwitter($partyCode, $socialNetworks)
    {
        if (empty($socialNetworks['twitter']) || empty($socialNetworks['twitter']['username'])) {
            $this->log->warning(" - Twitter data not found for " . $partyCode);
            return false;
        }

        $this->log->info("  + Starting Twitter import");
        $twData = $this->container->get('TwitterService')
            ->getTwitterData($partyCode, $socialNetworks['twitter']['username'], $this->scrapeData, $this->scrapeFull);

        if (!$twData) {
            $this->log->notice("  - ERROR while retrieving Twitter data for " . $partyCode);
            return false;
        }

        $this->log->info("    + Twitter data retrieved");
        $this->verify->verifyTwData($twData);
        $this->log->info("  + All Twitter data processed");
    }


    /**
     * GOOGLE PLUS scraping
     * @param string $partyCode
     * @param array  $socialNetworks
     */
    public function scrapeGooglePlus($partyCode, $socialNetworks)
    {
        if (empty($socialNetworks['googlePlus'])) {
            $this->log->warning(" - Google+ data not found for " . $partyCode);
            return false;
        }

        $this->log->info("  + Starting Google+ import");
        $gData = $this->container->get('GoogleService')
            ->getGooglePlusData($partyCode, $socialNetworks['googlePlus']);

        if (empty($gData)) {
            $this->log->notice("  - ERROR while retrieving Google+ data for " . $partyCode);
            return false;
        }

        $this->log->info("    + Google+ data retrieved");
        $this->log->info("      + Follower count added");
    }


    /**
     * YOUTUBE scraping
     * @param string $partyCode
     * @param array  $socialNetworks
     */
    public function scrapeYoutube($partyCode, $socialNetworks)
    {
        if (empty($socialNetworks['youtube'])) {
            $this->log->warning(" - Youtube data not found for " . $partyCode);
            return false;
        }

        $this->log->info("  + Starting Youtube import");
        $ytData = $this->container->get('GoogleService')
            ->getYoutubeData($partyCode, $socialNetworks['youtube'], $this->scrapeData);

        if (!$ytData) {
            $this->log->notice("  - ERROR while retrieving Youtube data for " . $partyCode);
            return false;
        }

        $this->log->info("  + Youtube data retrieved");
        $this->verify->verifyYtData($ytData);
        $this->log->info("  + All Google data processed");
    }

}
