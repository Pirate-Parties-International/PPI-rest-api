<?php
namespace AppBundle\Command;

use Symfony\Bundle\FrameworkBundle\Command\ContainerAwareCommand;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

use Pirates\PapiInfo\Compile;

use AppBundle\Entity\Metadata;
use AppBundle\Entity\Statistic;
use AppBundle\Extensions\ScraperServices;


class ScraperCommand extends ContainerAwareCommand
{

    protected function configure()
    {
        $this
            ->setName('papi:scraper')
            ->setDescription('Scrapes FB, TW and G+ data. Should be run once per day.')
            ->addOption('party', 'p', InputOption::VALUE_OPTIONAL, 'Choose a single party to scrape, by code (i.e. ppsi)')
            ->addOption('site', 'w', InputOption::VALUE_OPTIONAL, 'Choose a single website to scrape (fb, tw, g+ or yt)')
            ->addOption('data', 'd', InputOption::VALUE_OPTIONAL, 'Choose a single data type to scrape, fb only (info, posts, images, events)')
        ;
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $who   = $input->getOption('party'); // if null, get all
        $where = $input->getOption('site'); // if null, get all
        $what  = $input->getOption('data'); // if null, get all

        $this->container = $this->getContainer();
        $this->em = $this->container->get('doctrine')->getManager();

        $this->output = $output;
        $this->logger = $this->getContainer()->get('logger');
        $logger = $this->logger;

        $service = $this->container->get('ScraperServices');

        $output->writeln("##### Starting scraper #####");

        if (empty($who)) {
            $output->writeln("# Getting all parties");
            $parties = $service->getAllParties();
            $output->writeln("Done");
        } else {
            $output->writeln("# Getting one party (". $who .")");
            $parties = $service->getOneParty($who);
            $output->writeln("Done");
        }

        // Verify argument search terms
        if ($where != null && $where != 'yt' && $where != 'g+' && $where != 'tw' && $where != 'fb') {
            $output->writeln("     + ERROR - Search term \"". $where ."\" not recognised");
            $output->writeln("# Process halted");
            die;
        }
        if ($what != null && $where != 'fb') {
            $output->writeln("     + ERROR - Search term \"". $what ."\" only valid when limited to Facebook");
            $output->writeln("# Process halted");
            die;
        }

        foreach ($parties as $code => $party) {
            $output->writeln(" - Processing " . $code);
            $sn = $party->getSocialNetworks();

            if (empty($sn)) {
                continue;
            }

            //
            // FACEBOOK
            //
            if ($where == null || $where == 'fb') {
                if (!empty($sn['facebook']) && !empty($sn['facebook']['username'])) {
                    $output->writeln("     + Starting Facebook import");
                    $fd = $service->getFBData($sn['facebook']['username'], $what, $code); 

                    if ($what == null || $what == 'info') {
                        if ($fd == false || empty($fd['likes'])) {
                            $output->writeln("     + ERROR while retrieving FB data");
                        } else {
                            $output->writeln("     + Facebook data retrieved");

                            $service->addStatistic(
                                $code,
                                Statistic::TYPE_FACEBOOK, 
                                Statistic::SUBTYPE_LIKES,
                                $fd['likes']
                            );
                            $output->writeln("         + 'Like' count added");

                            $service->addStatistic(
                                $code,
                                Statistic::TYPE_FACEBOOK,
                                Statistic::SUBTYPE_TALKING,
                                $fd['talking']
                            );
                            $output->writeln("         + 'Talking about' count added");

                            $service->addStatistic(
                                $code,
                                Statistic::TYPE_FACEBOOK, 
                                Statistic::SUBTYPE_POSTS,
                                $fd['postCount']
                            );
                            $output->writeln("         + Post count added");

                            $service->addStatistic(
                                $code,
                                Statistic::TYPE_FACEBOOK,
                                Statistic::SUBTYPE_IMAGES,
                                $fd['photoCount']
                            );
                            $output->writeln("         + Photo count added");

                            $service->addStatistic(
                                $code,
                                Statistic::TYPE_FACEBOOK,
                                Statistic::SUBTYPE_EVENTS,
                                $fd['eventCount']
                            );
                            $output->writeln("         + Event count added");

                            $output->writeln("     + All statistics added");

                            $cover = $service->getFacebookCover($code, $fd['cover']);
                            $output->writeln("         + Cover retrieved");

                            $service->addMeta(
                                $code,
                                Metadata::TYPE_FACEBOOK_COVER,
                                $cover
                            );
                            $output->writeln("         + Cover added");

                            $service->addMeta(
                                $code,
                                Metadata::TYPE_FACEBOOK_DATA,
                                json_encode($fd['data'])
                            );
                            $output->writeln("         + General data added");
                        }
                    }

                    if ($what == null || $what == 'posts') {
                        if (!empty($fd['posts'])) {
                            $service->addMeta(
                                $code,
                                Metadata::TYPE_FACEBOOK_POSTS,
                                json_encode($fd['posts'])
                            );
                            $output->writeln("         + Posts added");
                        }
                    }
                    
                    if ($what == null || $what == 'images') {
                        if (!empty($fd['photos'])) {
                            $service->addMeta(
                                $code,
                                Metadata::TYPE_FACEBOOK_PHOTOS,
                                json_encode($fd['photos'])
                            );
                            $output->writeln("         + Photos added");
                        }
                    }

                    if ($what == null || $what == 'events') {
                        if (!empty($fd['events'])) {
                                $service->addMeta(
                                $code,
                                Metadata::TYPE_FACEBOOK_EVENTS,
                                json_encode($fd['events'])
                            );
                            $output->writeln("         + Events added");
                        }
                    }

                $output->writeln("     + Metadata added");
                }
            }

            //
            // TWITTER
            //
            if ($where == null || $where == 'tw') {
                if (!empty($sn['twitter']) && !empty($sn['twitter']['username'])) {
                    $output->writeln("     + Starting Twitter import");
                    $td = $service->getTwitterData($sn['twitter']['username'], $code);

                    if ($td == false ||
                        empty($td['followers']) ||
                        empty($td['tweets'])
                        ) {
                        $output->writeln("     + ERROR while retrieving TW data");
                    } else {
                        $output->writeln("     + Twitter data retrieved");

                        $service->addStatistic(
                            $code,
                            Statistic::TYPE_TWITTER,
                            Statistic::SUBTYPE_LIKES,
                            $td['likes']
                        );
                        $output->writeln("         + 'Like' count added");

                        $service->addStatistic(
                            $code,
                            Statistic::TYPE_TWITTER,
                            Statistic::SUBTYPE_FOLLOWERS,
                            $td['followers']
                        );
                        $output->writeln("         + Follower count added");

                        $service->addStatistic(
                            $code,
                            Statistic::TYPE_TWITTER,
                            Statistic::SUBTYPE_FOLLOWING,
                            $td['following']
                        );
                        $output->writeln("         + Following count added");

                        $service->addStatistic(
                            $code,
                            Statistic::TYPE_TWITTER,
                            Statistic::SUBTYPE_POSTS,
                            $td['tweets']
                        );
                        $output->writeln("         + Tweet count added");

                        $output->writeln("     + All statistics added");

                        $service->addMeta(
                            $code,
                            Metadata::TYPE_TWITTER_DATA,
                            json_encode($td['description'])
                        );
                        $output->writeln("         + General data added");

                        if (!empty($td['posts'])) {
                            $service->addMeta(
                                $code,
                                Metadata::TYPE_TWITTER_POSTS,
                                json_encode($td['posts'])
                            );
                            $output->writeln("         + Tweets added");
                        }

                        $output->writeln("     + Metadata added");
                    }
                }
            }

            //
            // Google+
            //
            if ($where == null || $where == 'g+') {
                if (!empty($sn['googlePlus'])) {
                    $output->writeln("     + Starting GooglePlus import");
                    $gd = $service->getGooglePlusData($sn['googlePlus']);

                    if ($gd == false ||
                        empty($gd)
                        ) {
                        $output->writeln("     + ERROR while retrieving G+ data");
                    } else {
                        $output->writeln("     + GooglePlus data retrieved");
                    
                        $service->addStatistic(
                            $code,
                            Statistic::TYPE_GOOGLEPLUS,
                            Statistic::SUBTYPE_FOLLOWERS,
                            $gd
                        );
                        $output->writeln("     + Statistic added");
                    }
                }
            }

            //
            // Youtube
            //
            if ($where == null || $where == 'yt') {
                if (!empty($sn['youtube'])) {
                    $output->writeln("     + Starting Youtube import");
                    $yd = $service->getYoutubeData($sn['youtube']);

                    if ($yd == false ||
                        empty($yd)
                        ) {
                        $output->writeln("     + ERROR while retrieving Youtube data");
                    } else {
                        $output->writeln("     + Youtube data retrieved");
                        
                        $service->addStatistic(
                            $code,
                            Statistic::TYPE_YOUTUBE,
                            Statistic::SUBTYPE_SUBSCRIBERS,
                            $yd['stats']['subscriberCount']
                        );
                        $output->writeln("         + Subscriber count added");
    
                        $service->addStatistic(
                            $code,
                            Statistic::TYPE_YOUTUBE,
                            Statistic::SUBTYPE_VIEWS,
                            $yd['stats']['viewCount']
                        );
                        $output->writeln("         + View count added");
                    
                        $service->addStatistic(
                            $code,
                            Statistic::TYPE_YOUTUBE,
                            Statistic::SUBTYPE_VIDEOS,
                            $yd['stats']['videoCount']
                        );
                        $output->writeln("         + Video count added");
                    
                        $output->writeln("     + All statistics added");

                        if (!empty($yd['videos'])) {
                            $service->addMeta(
                                $code,
                                Metadata::TYPE_YOUTUBE_VIDEOS,
                                json_encode($yd['videos'])
                            );
                            $output->writeln("         + Videos added");
                        }

                        $output->writeln("     + Metadata added");
                    }
                }
            }
        }

        $output->writeln("# Saving to DB");
        $this->em->flush();

        $output->writeln("# Done");        
        
    }

}