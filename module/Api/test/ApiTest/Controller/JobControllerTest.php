<?php

namespace ApiTest\Controller;

use Api\Entity\Job;
use Xmlps\UnitTest\ControllerTest;

class ApiControllerTest extends ControllerTest
{
    protected $document;
    protected $job;
    protected $user;

    protected $testFile = '/tmp/UNITTEST_document.txt';

    protected $citationStyles;

    /**
     * Set up the controller test
     *
     * @return void
     */
    public function setUp()
    {
        parent::setUp();

        $this->citationStyles = $this->sm->get('CitationstyleConversion\Model\Citationstyles');

        $this->resetTestData();
    }

    /**
     * Test if the submit action canot be accessed by guests
     *
     * @return void
     */
    public function testSubmitActionCannotBeAccessedLoggedOut()
    {
        $this->dispatch($this->buildQuery('submit', array(), false));
        $this->assertResponseStatusCode(403);
    }
    
    /**
     * Test old job submission using plaintext password
     *
     * @return void
     */
    public function testOldSubmitAction()
    {
        $styleMap = $this->citationStyles->getStyleMap();
        $keys = array_keys($styleMap);

        $data = array(
            'fileName' => 'Testfile.txt',
            'fileContent' => base64_encode('Test Content'),
            'citationStyleHash' => $keys[0],
            'email' => $this->userEmail,
            'password' => $this->userPassword,
        );

        $this->getRequest()->setMethod('POST')->setPost(new \Zend\Stdlib\Parameters($data));
        $this->dispatch($this->buildQuery('submit', array(), false));
        $this->assertResponseStatusCode(403);
    }

    /**
     * Test if a job can be submitted
     *
     * @return void
     */
    public function testSubmitAction()
    {
        $styleMap = $this->citationStyles->getStyleMap();
        $keys = array_keys($styleMap);

        $data = array(
            'fileName' => 'Testfile.txt',
            'fileContent' => base64_encode('Test Content'),
            'citationStyleHash' => $keys[0],
            'email' => $this->userEmail,
            'access_token' => $this->apiAccessToken,
        );

        $this->getRequest()->setMethod('POST')->setPost(new \Zend\Stdlib\Parameters($data));
        $this->dispatch($this->buildQuery('submit', array(), false));
        $this->assertResponseStatusCode(200);
        $response = json_decode($this->getResponse()->getContent());
        $this->assertTrue(is_object($response));
        $this->assertSame($response->status, 'success');
        $this->assertNotEmpty($response->id);
    }

    /**
     * Test if the job status action works properly
     *
     * @return void
     */
    public function testStatusAction()
    {
        $this->dispatch($this->buildQuery('status', array('id' => $this->job->id)));
        $this->assertResponseStatusCode(200);
        $response = json_decode($this->getResponse()->getContent());
        $this->assertTrue(is_object($response));
        $this->assertSame($response->status, 'success');
        $this->assertTrue(isset($response->jobStatus));
        $this->assertTrue(isset($response->jobStatusDescription));
    }

    /**
     * Buid a query to the API
     *
     * @param mixed $call API endpoint to call
     * @param array $data Query parameter data
     * @param bool $authorized Whether or not to authorize the test user
     * @return void
     */
    protected function buildQuery($call, $data = array(), $authorized = true)
    {
        // Add the test user credentials to the query
        if ($authorized) {
            $data['email'] = $this->userEmail;
            $data['access_token'] = $this->apiAccessToken;
        }
        return '/api/job/' . $call . '?' . http_build_query($data);
    }


    /**
     * Creates test data for this test
     *
     * @return void
     */
    protected function createTestData()
    {
        touch($this->testFile);
        file_put_contents($this->testFile, rand());

        // Create test user
        $this->user = $this->createTestUser();

        // Create test job
        $this->job = $this->createTestJob(
            array(
                'user' => $this->user,
                'status' => 2, // JOB_STATUS_COMPLETED
                'conversionStage' => 10, // JOB_CONVERSION_STAGE_ZIP
            )
        );

        // Create test document
        $this->document = $this->createTestDocument(
            array(
                'job' => $this->job,
                'path' => $this->testFile,
                'conversionStage' => $this->job->conversionStage,
            )
        );
        $this->job->documents[] = $this->document;

        $this->getJobDAO()->save($this->job);
    }

    /**
     * Cleans test data after test
     *
     * @return void
     */
    protected function cleanTestData()
    {
        @unlink($this->testFile);

        $this->deleteTestUser();
    }
}
