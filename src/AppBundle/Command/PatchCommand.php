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
	protected function configure()
	{
		$this
			->setName('papi:patch')
			->setDescription('Patches existing db entries')
            ->addOption('twitter', 't', InputOption::VALUE_NONE, "Fix 'postId' field in twitter images and videos")
            ->addOption('charset', 'c', InputOption::VALUE_NONE, "Convert 'postText' field to utf8mb4 character set")
        ;
	}

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $this->container = $this->getContainer();
        $this->em = $this->container->get('doctrine')->getManager();

        $this->output = $output;
        $this->logger = $this->getContainer()->get('logger');
        $logger = $this->logger;

        switch (true) { // add more options here
            case $input->getOption('twitter'):
                $output->writeln("##### Patching twitter images #####");
                $this->patchTwitterImages();
                break;
            case $input->getOption('charset'):
            	$output->writeln("##### Patching 'postText' charset #####");
            	$this->patchCharset();
            	break;
            default:
                $output->writeln("Invalid option.");
        }
    }

    public function getConfirmation() {
    	echo "##### CAUTION: THIS WILL ALTER THE DATABASE! #####\n";
    	echo "  It is recommended to make a backup of your database before performing this action.\n";
    	echo "  Do you wish to continue? y/n - ";

    	$handle = fopen ("php://stdin","r");
		$line = fgets($handle);

		if (trim($line) != 'y' && trim($line) != 'yes'){
    		echo "  Process aborted.\n";
    		exit;
		} else {
			echo "\n";
		}
    }


/////
// Charset for 'postText' field
/////
    public function patchCharset() {
    	$old = $this->checkCharset();

		if ($old['char'] == 'utf8mb4' && $old['data'] == 'longtext') {
			echo ", no fix needed.\n";
			return;
		}

		echo ", fix needed.\n";
		$this->getConfirmation();
		$this->fixCharset();
		$new = $this->checkCharset();

		if ($new['char'] == 'utf8mb4' && $new['data'] == 'longtext') {
			echo ", great success! :D\n";
			return;
		}

		echo ", process failed. :(\n";
		echo "  Your database may need to be altered manually. Contact us on github if you need help.\n";
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
		echo "  Current character set is ".$array['char'].", data type is ".$array['data'];
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


/////
// Twitter images
/////

    /**
     * Finds all Twitter images and videos to patch
     */
    public function patchTwitterImages()
    {
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
		echo "  All done.\n";
	}

	/**
	 * Validates postId by comparing to postUrl
	 * Replaces postId if invalid
	 */
	public function getPostIdFromUrl($post)
	{
		$oldId = $post->getPostId();
		echo "  postId = ".$oldId;
		$postData = $post->getPostData();
		$postUrl = $postData['url'];
		$newId = substr($postUrl, -18);
		echo ", postUrl = ".$postUrl.", newId = ".$newId;

		if ($oldId !== $newId) {
			echo ", replacing...\n";
			$post->setPostId($newId);
			$this->em->persist($post);
		} else {
			echo ", no fix needed.\n";
		}
	}

}