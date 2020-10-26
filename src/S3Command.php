<?php

namespace Wirelab\Provisionary\Console;

use Aws\Exception\AwsException;
use Aws\S3\S3Client;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Question\Question;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Process\Process;

class S3Command extends Command
{
    const DEFAULT_REGION = 'eu-west-1';

    const API_VERSION = '2006-03-01';

    protected $client;

    /**
     * @param InputInterface $input
     * @param OutputInterface $output
     */
    protected function init(InputInterface $input, OutputInterface $output)
    {
        $this->client = $this->_getClient($input, $output);
    }

    /**
     * {@inheritDoc}
     */
    protected function configure()
    {
        $this
            ->setName('s3')
            ->setDescription('Provision and S3 bucket')
            ->addArgument('name')
            ->addOption('cloudfront', null, InputOption::VALUE_NONE, 'Provisions a Cloudfront distribution for the newly created bucket');
    }

    /**
     * @param InputInterface $input
     * @param OutputInterface $output
     * @return int
     */
    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $this->init($input, $output);

        if($input->getOption('cloudfront')) {
            // TODO Cloudfront
        }

        // Call for configuration of aws client profile
        $output->writeln('<comment>The tool will ask to configure AWS, if you have already configured a profile with the given name just hit enter a couple of times</comment>');
        $process = Process::fromShellCommandline('aws configure --profile ' . $input->getArgument('name'));
        $process->setTty(true);
        $process->start();
        $process->wait();


        // Create a bucket
        $this->createBucket($input, $output);

        return 1;
    }

    /**
     * @param InputInterface $input
     * @param OutputInterface $output
     */
    protected function createBucket(InputInterface $input, OutputInterface $output)
    {
        try {
            $result = $this->client->createBucket([
                'Bucket' => $input->getArgument('name'),
            ]);

            $output->writeLn("Bucket created in {$result['Location']} effective URI: {$result['@metadata']['effectiveUri']}");
        } catch (AwsException $e) {
            $output->writeln($e->getAwsErrorMessage());
        }
    }

    protected function _getClient($input, $output): S3Client
    {

        $helper = $this->getHelper('question');
        $question = new Question('Please enter the region of choice for the buckets (defaults to ' .  self::DEFAULT_REGION . ')', self::DEFAULT_REGION);
        $region = $helper->ask($input, new SymfonyStyle($input, $output), $question);

        return new S3Client([
            'version' => self::API_VERSION,
            'region'  => $region
        ]);
    }

}