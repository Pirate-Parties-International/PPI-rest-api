<?php
namespace AppBundle\Command;

use Symfony\Bundle\FrameworkBundle\Command\ContainerAwareCommand;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;


use AppBundle\Entity\SocialMedia;
use Pirates\PapiInfo\Compile;

class PatchCommand extends ContainerAwareCommand
{
	protected function configure() {
		$this
			->setName('papi:patch')
			->setDescription('Patches existing db entries')
            ->addOption('charset',    'c', InputOption::VALUE_NONE, "Convert social_media 'postText' field to utf8mb4 character set")
            ->addOption('metadata',   'm', InputOption::VALUE_NONE, "Convert metadata 'value' field to utf8mb4 character set")
            ->addOption('twitter',    't', InputOption::VALUE_NONE, "Fix 'postId' field in Twitter images and videos")
            ->addOption('postdata',   'p', InputOption::VALUE_NONE, "Rename certain 'postData' array keys for consistency")
            ->addOption('stats',      'x', InputOption::VALUE_NONE, "Alter Twitter and Facebook stat codes for consistency")
            ->addOption('exturls',    'u', InputOption::VALUE_NONE, "Decode Facebook's external image urls")
            ->addOption('duplicates', 'd', InputOption::VALUE_NONE, "Scan the social media database for duplicate entries")
            ->addOption('party',      'y', InputOption::VALUE_OPTIONAL, "Choose a single party to patch, by code")
            ->addOption('resume',     'z', InputOption::VALUE_OPTIONAL, "Choose a party to resume patching from, if interrupted")
        ;
    }

    protected function execute(InputInterface $input, OutputInterface $output) {
        $this->container = $this->getContainer();
        $this->em = $this->container->get('doctrine')->getManager();

        $this->output = $output;
        $this->log    = $this->getContainer()->get('logger');


        switch (true) { // add more options here
            case $input->getOption('twitter'):
                $this->log->notice("##### Patching Twitter images #####");
                $this->getConfirmation();
                $this->patchTwitterImages();
                break;
            case $input->getOption('charset'):
                $this->log->notice("##### Patching 'postText' charset #####");
                $this->patchCharset();
                break;
            case $input->getOption('postdata'):
                $this->log->notice("##### Patching 'postData' array keys #####");
                $this->getConfirmation();
                $this->patchPostData();
                break;
            case $input->getOption('stats');
                $this->log->notice("##### Patching stat codes #####");
                $this->patchStatCodes();
                break;
            case $input->getOption('exturls');
                $this->log->notice("##### Patching external image urls #####");
                $this->patchEncodedUrls();
                break;
            case $input->getOption('duplicates');
                $this->log->notice('##### Patching duplicate social media posts #####');
                $partyCode   = $input->getOption('party');
                $resumePoint = $input->getOption('resume');
                $this->patchDuplicateEntries($partyCode, $resumePoint);
                break;
            case $input->getOption('metadata');
                $this->log->notice('##### Patching metadata charset #####');
                $this->patchMetadata();
                break;
            default:
                $output->writeln("Invalid option.");
        }
    }

    public function getConfirmation() {
        $this->log->notice("### CAUTION: THIS WILL ALTER THE DATABASE! ###");
        $this->output->writeln("  It is recommended to make a backup of your database before performing this action.");
        $this->output->write("  Do you wish to continue? y/n - ");

        $handle = fopen ("php://stdin","r");
        $line = fgets($handle);

        if (trim($line) != 'y' && trim($line) != 'yes'){
            $this->log->notice("Process aborted.");
            exit;
        }
    }


/////
// duplicate entries (--duplicates, -d)
//
// Occasionally the scraper will bug out and add duplicate entries of the same social media posts.
// This patch locates those posts in the database and deletes the duplicates, leaving only the most recent copy.
// Only run this if you know there are duplicate entries. It takes too long to waste time running it needlessly.
/////
    public function patchDuplicateEntries($partyCode = null, $resumePoint = null) {
        $this->getConfirmation();
        $time = new \DateTime('now');
        $this->log->notice("# NOTE: This will take a long time. Go and make yourself a cup of tea. The time is now " . $time->format('H:i:s') . ".");
        $this->log->info("Checking database... ");

        if (is_null($partyCode)) {
            $social = $this->em->getRepository('AppBundle:SocialMedia')->findAll();

            $size = sizeof($social);
            $this->log->info($size . " total posts found...");

            $estLow  = ($size / 4) / 60; // estimation of minutes based on 4 posts per second
            $estHigh = ($size / 3) / 60; // estimation of minutes based on 3 posts per second
            $this->log->info("Estimated time to process all posts... " . ceil($estLow / 60) . "-" . ceil($estHigh / 60) . " hours...");

            $parties = $this->container->get('DatabaseService')->getAllParties();
        } else {
            $parties = $this->container->get('DatabaseService')->getOneParty($partyCode);
        }

        foreach ($parties as $party) {
            if (!is_null($resumePoint) && ($party->getCode() < strtoupper($resumePoint))) {
                $this->log->debug("Skipping " . $party->getCode());
                continue;
            }

            $this->log->info("Getting posts from " . $party->getCode());
            $posts = $this->em->getRepository('AppBundle:SocialMedia')->findBy(['code' => $party->getCode()], ['id' => 'DESC']);

            $size = sizeof($posts);
            $this->log->info($size . " posts found...");

            $estLow  = ($size / 4) / 60; // estimation of minutes based on 4 posts per second
            $estHigh = ($size / 3) / 60; // estimation of minutes based on 3 posts per second
            $this->log->info("Estimated time to process " . $party->getCode() . "... " . ceil($estLow) . "-" . ceil($estHigh) . " minutes...");

            $this->output->write("Processing...");
            $postCount = 0;
            foreach ($posts as $prime) {
                $postCount++;
                $terms = [
                    'code'      => $party->getCode(),
                    'type'      => $prime->getType(),
                    'subType'   => $prime->getSubType(),
                    'postId'    => $prime->getPostId(),
                    'postText'  => $prime->getPostText(),
                    'postImage' => $prime->getPostImage()
                    ];

                $dupes = $this->em->getRepository('AppBundle:SocialMedia')->findBy($terms, ['id' => 'DESC']);

                if (sizeof($dupes) == 1) {
                    // $this->log->debug($postCount . " - No duplicates found for " . $prime->getPostId());
                    $this->output->write($postCount . ",");
                    continue;
                }

                foreach ($dupes as $dupe) {
                    $this->log->notice($postCount . " - " . sizeof($dupes) . " duplicates found for " . $prime->getPostId());

                    if ($dupe->getId() < $prime->getId()) {
                        $this->output->write("prime type = " . $prime->getType() . "-" . $prime->getSubType());
                        $this->output->write(", id = " . $prime->getId() . ", post id = " . $prime->getPostId());
                        $this->output->writeln(", time = " . $prime->getPostTime()->format('Y-m-d H:i:s'));
                        $this->output->write(" dupe type = " . $dupe->getType() . "-" . $dupe->getSubType());
                        $this->output->write(", id = " . $dupe->getId() . ", post id = " . $dupe->getPostId());
                        $this->output->writeln(", time = " . $dupe->getPostTime()->format('Y-m-d H:i:s'));
                        $this->output->writeln("prime text = " . $prime->getPostText());
                        $this->output->writeln(" dupe text = " . $dupe->getPostText());
                        $this->output->writeln("prime image = " . $prime->getPostImage());
                        $this->output->writeln(" dupe image = " . $dupe->getPostImage());
                        $this->log->info("Deleting...");
                        $this->em->remove($dupe);
                        $this->em->flush();
                        $this->log->info("Done...");
                    }
                }
            }

            $mid  = new \Datetime('now');
            $diff = $time->diff($mid);
            $this->log->info("Done with " . $party->getCode() . " in " . $diff->format('%H:%I:%S'));
        }

        $end  = new \Datetime('now');
        $diff = $time->diff($end);
        $this->log->notice("All done in " . $diff->format('H:%I:%S'));
    }


/////
// external image urls (--exturls, -e)
//
// Facebook's external image urls are encoded by default.
// The scraper has been updated to decode these before saving them to the database.
// This patch finds encoded urls in the database and decodes them.
/////
    public function patchEncodedUrls () {
        $this->getConfirmation();

        $this->log->info("  Checking database... ");
        $posts = $this->em->getRepository('AppBundle:SocialMedia')->findBy(['type' => 'fb']);

        $this->log->info("patching...");
        $patchCount = 0;
        foreach ($posts as $post) {
            $data   = $post->getPostData();
            $imgSrc = $data['img_source'];

            if ($imgSrc && strpos($imgSrc, 'external.xx.fbcdn.net')) {
                $stPos  = strpos($imgSrc, '&url=') +5;
                $edPos  = strpos($imgSrc, '&cfs=');
                $length = $edPos - $stPos;
                $temp   = substr($imgSrc, $stPos, $length);
                $imgSrc = urldecode($temp);

                $data['img_source'] = $imgSrc;
                $post->setPostData($data);
                $this->em->persist($post);

                $patchCount++;
            }
            $this->output->write($patchCount . ",");
        }

        if (!$patchCount) {
            $this->log->info("The database is up to date. No patch needed.");
            exit;
        }

        $this->log->notice($patchCount . " entries patched... saving to DB...");
        $this->em->flush();
        $this->log->notice("All done.");
    }


/////
// stat codes (--stats, -x)
//
// The scraper originally logged stats for tw-T (for 'Tweets').
// When we started scraping Facebook too, this was changed to P (for 'Posts') as fb-T was used for the 'Talking About' stat.
// The scraper has been altered to log them as T again (for 'Text') to make them consistent with the older stats.
// This patch changes those stats that are already in the database back to T, and changes fb-T to fb-A.
/////
    public function patchStatCodes() {
        $posts = $this->em->getRepository('AppBundle:Statistic')->findBy(['subType' => 'P']);

        if (empty($posts)) {
            $this->log->notice("The database is up to date. No patch needed.");
            exit;
        }

        $this->getConfirmation();

        $this->log->info("Checking tweets...");
        $tweets = $this->em->getRepository('AppBundle:Statistic')->findBy(['type' => 'tw', 'subType' => 'P']);

        if (!empty($tweets)) {
            $this->log->info("Patching...");

            foreach ($tweets as $tweet) {
                $tweet->setSubType('T');
                $this->em->persist($tweet);
                $this->output->write(".");
            }

            $this->log->notice("All patched, saving to DB...");
            $this->em->flush();
            $this->log->info("Done");

        } else $this->log->notice("No patch needed.");

        $this->log->notice("Checking Facebook statuses...");
        $statuses = $this->em->getRepository('AppBundle:Statistic')->findBy(['type' => 'fb', 'subType' => 'P']);

        if (!empty($statuses)) {
            $this->log->info("Patching...");

            $talking = $this->em->getRepository('AppBundle:Statistic')->findBy(['type' => 'fb', 'subType' => 'T']);
            foreach ($talking as $talk) {
                $talk->setSubType('A');
                $this->em->persist($talk);
                $this->output->write(".");
            }

            foreach ($statuses as $status) {
                $status->setSubType('T');
                $this->em->persist($status);
                $this->output->write(".");
            }

            $this->log->notice("All patched, saving to DB...");
            $this->em->flush();
            $this->log->info("Done.");

        } else $this->log->notice("No patch needed.");

        $this->log->notice("All done.");
    }


/////
// postData array keys (--postdata, -p)
//
// The scraper originally logged data from different sources using each site's original term for the fields.
// e.g. Twitter has 'text' where Facebook has 'message', 'story' or 'caption', depending on the post type.
// The scraper has been updated to use consistent field names for all sites, making it easier for the api.
// This patch alters these fields for posts already in the database.
/////
    public function patchPostData() {
        $this->log->notice("Getting posts from DB...");
        $socialMedia = $this->em->getRepository('AppBundle:SocialMedia')->findAll();
        $this->log->info("Patching...");
 
        foreach ($socialMedia as $social) {
            $temp = $social->getPostData();
            $type = $social->getType();
            $img  = (null != $social->getPostImage()) ? $social->getPostImage() : null;

            if (isset($temp['img_source'])) {
                // no patch needed, do nothing
            } else if ($type != 'tw' && isset($temp['text'])) {
                // no patch needed, do nothing

            } else {
                if ($type == 'tw' && !is_null($img)) {
                    $temp['img_source'] = $temp['image'];
                    $temp['image'] = $img;
                    $this->output->write(".");

                } else if ($type == 'yt') {
                    $temp['text'] = $temp['title'];
                    $temp['image'] = $img;
                    $temp['img_source'] = $temp['thumb'];
                    unset($temp['title']);
                    unset($temp['thumb']);
                    $this->output->write(".");

                } else if ($type == 'fb') {
                    switch ($social->getSubType()) {
                        case 'I': // images
                            $temp['text'] = $temp['caption'];
                            $temp['image'] = $img;
                            $temp['img_source'] = $temp['source'];
                            unset($temp['caption']);
                            unset($temp['source']);
                            $this->output->write(".");
                            break;

                        case 'E': // events
                            $temp['text'] = $temp['name'];
                            $temp['description'] = $temp['details']['description'];
                            $temp['image'] = $img;
                            $temp['img_source'] = $temp['details']['cover']['source'];
                            $temp['place'] = $temp['details']['place'];
                            $temp['address'] = $temp['details']['address'];
                            unset($temp['name']);
                            unset($temp['details']);
                            $this->output->write(".");
                            break;

                        case 'T': // text posts
                        case 'V': // videos
                            $story = isset($temp['story']) ? $temp['story'] : null;
                            $temp['text'] = isset($temp['message']) ? $temp['message'] : $story;
                            $temp['image'] = $img;
                            $temp['img_source'] = isset($temp['link']['thumb']) ? $temp['link']['thumb'] : null;
                            unset($temp['message']);
                            unset($temp['story']);
                            unset($temp['link']['thumb']);
                            $this->output->write(".");
                    }
                }
                $social->setPostData($temp);
                $this->em->persist($social);
            }
        }
        $this->log->info("All patched. Saving to DB...");
        $this->em->flush();
        $this->log->notice("Done.");
    }


/////
// Charset for social_media 'postText' field (--charset, -c)
// Charset for metadata 'value' field (--metadata, -m)
//
// The database was originally encoded to utf8, which is the standard.
// This made it impossible to log 4-byte characters, such as emojis or certain languages like Japanese and Chinese.
// This meant that the scraper often failed when trying to save social media posts.
// This patch alters the social media postText field to use utf8mb4, which supports 4-byte characters.
/////
    public function patchCharset() {
        $old = $this->checkCharset();
		$this->log->info("Current character set is " . $old['char'] . ", data type is " . $old['data']);

        if ($old['char'] == 'utf8mb4' && $old['data'] == 'longtext') {
            $this->log->info("No patch needed.");
            return;
        }

        $this->log->info("Patching...");
        $this->getConfirmation();
        $this->fixCharset();
        $new = $this->checkCharset();
        $this->log->info("Character set is now " . $new['char'] . ", data type is " . $new['data']);

        if ($new['char'] == 'utf8mb4' && $new['data'] == 'longtext') {
            $this->log->info("Great success! :D");
            return;
        }

        $this->log->info("Process failed. :(");
        $this->log->info("Your database may need to be altered manually. Contact us on github if you need help.");
    }

    /**
     * Checks current charset
     */
    public function checkCharset() {
        $db_name = $this->container->getParameter('database_name');

        $sql1 = "SELECT character_set_name
            FROM information_schema.columns
            WHERE table_schema = '$db_name'
            AND table_name = 'social_media'
            AND column_name = 'postText';";

        $query = $this->em->getConnection()->prepare($sql1);
        $query->execute();
        $answer['char'] = $query->fetchAll();

        $sql2 = "SELECT data_type
            FROM information_schema.columns
            WHERE table_schema = '$db_name'
            AND table_name = 'social_media'
            AND column_name = 'postText';";

        $query = $this->em->getConnection()->prepare($sql2);
        $query->execute();
        $answer['data'] = $query->fetchAll();

        $array['char'] = $answer['char'][0]['character_set_name'];
        $array['data'] = $answer['data'][0]['data_type'];
		return $array;
    }

    /**
     * Converts charset to 4-byte compatible utf8mb4
     */
    public function fixCharset() {
    	$sql = "ALTER TABLE social_media
    		CHANGE postText postText LONGTEXT
    		CHARACTER SET utf8mb4
    		COLLATE utf8mb4_unicode_ci;";

        $query = $this->em->getConnection()->prepare($sql);
        $query->execute();
    }


    /**
     * Same patch for metadata
     */
    public function patchMetadata() {
        $old = $this->checkMetadata();
        $this->log->info("Current character set is " . $old);

        if ($old == 'utf8mb4') {
            $this->log->info("No patch needed.");
            return;
        }

        $this->log->info("Patching...");
        $this->getConfirmation();
        $this->fixMetadata();
        $new = $this->checkMetadata();
        $this->log->info("Charset is now " . $new);

        if ($new == 'utf8mb4') {
            $this->log->info("Great success! :D");
            return;
        }

        $this->log->info("Process failed. :(");
        $this->log->info("Your database may need to be altered manually. Contact us on github if you need help.");
    }

    /**
     * Checks current charset
     */
    public function checkMetadata() {
        $db_name = $this->container->getParameter('database_name');

        $sql = "SELECT character_set_name
            FROM information_schema.columns
            WHERE table_schema = '$db_name'
            AND table_name = 'metadata'
            AND column_name = 'value';";

        $query = $this->em->getConnection()->prepare($sql);
        $query->execute();
        $response = $query->fetchAll();
        $answer = $response[0]['character_set_name'];

        return $answer;
    }

    /**
     * Converts charset to 4-byte compatible utf8mb4
     */
    public function fixMetadata() {
        $sql = "ALTER TABLE metadata
            CHANGE value value LONGTEXT
            CHARACTER SET utf8mb4
            COLLATE utf8mb4_unicode_ci;";

        $query = $this->em->getConnection()->prepare($sql);
        $query->execute();
    }

/////
// Twitter images (--twitter, -t)
//
// The scraper originally logged incorrect IDs for linking back to Twitter images and videos.
// The scraper has been updated to correct this issue.
// This patch fixes old databse entries by taking the correct data from the postUrl and applying it to the postId.
/////
    public function patchTwitterImages() {
        $smRepo = $this->em->getRepository('AppBundle:SocialMedia');
        $twImgs = $smRepo->findBy(['type' => 'tw', 'subType' => 'i']);
        $twVids = $smRepo->findBy(['type' => 'tw', 'subType' => 'v']);

		foreach ($twImgs as $img) {
			$this->getPostIdFromUrl($img);
		}

		foreach ($twVids as $vid) {
			$this->getPostIdFromUrl($vid);
		}

		$this->em->flush();
		$this->log->notice("All done.");
	}

	/**
	 * Validates postId by comparing to postUrl
	 * Replaces postId if invalid
	 */
	public function getPostIdFromUrl($post) {
		$oldId = $post->getPostId();
		$this->output->write("postId = " . $oldId);
		$postData = $post->getPostData();
		$postUrl = $postData['url'];
		$newId = substr($postUrl, -18);
		$this->output->write(", postUrl = " . $postUrl . ", newId = " . $newId);

		if ($oldId !== $newId) {
			$this->output->writeln(", replacing...");
			$post->setPostId($newId);
			$this->em->persist($post);
		} else {
			$this->output->writeln(", no fix needed.");
		}
	}

}