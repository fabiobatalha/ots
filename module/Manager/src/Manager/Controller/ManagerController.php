<?php
namespace Manager\Controller;

use Zend\Mvc\Controller\AbstractActionController;
use Zend\Mvc\I18n\Translator;
use Zend\Http\Headers;
use Xmlps\Logger\Logger;
use Manager\Model\Queue\Manager;
use Manager\Form\UploadForm;
use Manager\Form\UploadFormInputFilter;
use Manager\Model\DAO\DocumentDAO;
use Manager\Model\DAO\JobDAO;
use Manager\Entity\Job;
use Zend\Http\Response;

class ManagerController extends AbstractActionController {
    protected $logger;
    protected $translator;
    protected $queueManager;
    protected $uploadForm;
    protected $uploadFormInputFilter;
    protected $documentDAO;
    protected $jobDAO;

    /**
     * Constructor
     *
     * @param Logger $logger
     * @param Translator $translator
     * @param Manager $queueManager
     * @param UploadForm $uploadForm
     * @param UploadFormInputFilter $uploadFormInputFilter
     * @param DocumentDAO $documentDAO
     * @param JobDAO $jobDAO
     *
     * @return void
     */
    public function __construct(
        Logger $logger,
        Translator $translator,
        Manager $queueManager,
        UploadForm $uploadForm,
        UploadFormInputFilter $uploadFormInputFilter,
        DocumentDAO $documentDAO,
        JobDAO $jobDAO
    )
    {
        $this->logger = $logger;
        $this->translator = $translator;
        $this->queueManager = $queueManager;
        $this->uploadForm = $uploadForm;
        $this->uploadFormInputFilter = $uploadFormInputFilter;
        $this->documentDAO = $documentDAO;
        $this->jobDAO = $jobDAO;
    }

    /**
     * Upload action
     *
     * @return mixed Array containing view variables
     */
    public function uploadAction()
    {
        $request = $this->getRequest();
        if ($this->request->isPost()) {
            $data = array_merge_recursive(
                $request->getPost()->toArray(),
                $request->getFiles()->toArray()
            );

            $this->uploadForm->setInputFilter(
                $this->uploadFormInputFilter->getInputFilter()
            );
            $this->uploadForm->setData($data);
            if ($this->uploadForm->isValid()) {
                // Run file input filters (move the file)
                $data = $this->uploadFormInputFilter
                    ->getInputFilter()
                    ->getValues();

                $flashMessenger = $this->flashMessenger();
                $flashMessenger->setNamespace('success');
                $flashMessenger->addMessage(
                    $this->translator->translate(
                        'manager.upload.success'
                    )
                );

                // Create a new job and set the citation style file based on
                // the submitted citation style
                $job = $this->jobDAO->getInstance();
                $job->user = $this->identity();
                $job->setCitationStyleFileByTitle($data['citationStyle']);
                $this->jobDAO->save($job);

                // Move the uploaded file to its job directory
                $upload = $data['upload'];
                $upload['path'] = $job->getUploadPath() . '/' . $upload['name'];
                rename($upload['tmp_name'], $upload['path']);

                // Create new document
                $document = $this->documentDAO->getInstance();
                $document->job = $job;
                $document->conversionStage = $job->conversionStage;
                $document->path = $upload['path'];
                $this->documentDAO->save($document);

                $this->logger->infoTranslate('manager.job.createLog', $job->id);

                // Send the job to the queue manager
                $this->queueManager->addJob($job->id);

                return $this->redirect()->toRoute('manager', array('action' => 'list'));
            }
        }

        return array('uploadForm' => $this->uploadForm);
    }

    /**
     * List action
     *
     * @return mixed Array containing view variables
     */
    public function listAction()
    {
        // Get the paginator
        $user = $this->identity();
        if ($user->isAdministrator()) {
            $paginator = $this->jobDAO->getAdminJobPaginator();
        }
        else {
            $paginator = $this->jobDAO->getJobPaginator($user);
        }
        $page = $this->params()->fromRoute('page');
        $paginator ->setCurrentPageNumber($page);
        $paginator->setItemCountPerPage(20);

        // Display error if we got no messages
        if ($paginator->count() == 0) {
            $this->layout()->messages = array(
                'info' => array( $this->translator->translate(
                    'manager.job.noEntriesFound'
                )),
            );
            return;
        }

        return array('jobs' => $paginator);
    }

    /**
     * Lists details of a job
     *
     * @return mixed Array containing view variables
     */
    public function detailsAction()
    {
        $jobId = (int) $this->params()->fromRoute('id');
        $user = $this->identity();

        $jobParams = array('id' => $jobId);
        if (!$user->isAdministrator()) {
            // NOTE: We use class_exists() here to trigger the autoloader to
            // import the JOB_STATUS_* constants
            class_exists('Manager\Entity\Job');
            $jobParams['status'] = JOB_STATUS_COMPLETED;
            $jobParams['user'] = $user;
        }

        if (!($job = $this->jobDAO->findOneBy($jobParams))) {
            $this->getResponse()->setStatusCode(404);
            return;
        }

        return array('job' => $job);
    }

    /**
     * Download a processed document
     */
    public function downloadAction()
    {
        $documentId = (int) $this->params()->fromRoute('id');

        $user = $this->identity();
        if (
            !($document = $this->documentDAO->find($documentId)) or
            ($document->job->user->id != $user->id and !$user->isAdministrator())
        ) {
            $this->getResponse()->setStatusCode(404);
            return;
        }

        $response = $this->getEvent()->getResponse();
        $response->setHeaders(Headers::fromString(
            "Content-Type: {$document->mimeType}\r\n" .
            "Content-Length: {$document->size}\r\n" .
            "Content-Disposition: attachment; filename=\"{$document->getFileName()}\""
        ));
        $response->setContent(file_get_contents($document->path));

        return $response;
    }
    
    /**
     * Edit jats document
     */
    public function editorAction()
    {
        $jobId = (int) $this->params()->fromRoute('id');
        
        $user = $this->identity();
        if (
            !($job = $this->jobDAO->find($jobId)) or
            ($job->user->id != $user->id and !$user->isAdministrator())
        ) {
            $this->getResponse()->setStatusCode(404);
            return;
        }
        
        $this->layout()->setTemplate('layout/editor')->setVariable('jobId', $jobId);
                
    }
    
    /**
     * get raw jats file
     */
    public function xmlAction()
    {
        $jobId = (int) $this->params()->fromRoute('id');
        $jobParams = array('id' => $jobId);
        
        if (!($job = $this->jobDAO->findOneBy($jobParams))) {
            $this->getResponse()->setStatusCode(404);
            return;
        }
        
        $document = null;
        if (!($document = $job->getStageDocument(JOB_CONVERSION_STAGE_BIBTEXREFERENCES))) {
        	// pdf uploads
        	$document = $job->getStageDocument(JOB_CONVERSION_STAGE_XML_MERGE); 
        }
        $user = $this->identity();
        
        if (
                $document->job->user->id != $user->id and !$user->isAdministrator()
                ) {
            $this->getResponse()->setStatusCode(404);
            return;
        }
        
        if ($this->request->isPost()) {
            $response = $this->getEvent()->getResponse();
            $response->setHeaders(Headers::fromString(
                "Content-Type: application/json\r\n"
            ));
            
            $body = $this->getRequest()->getContent();
            $data = (array) json_decode($body);
            
            // content is required
            if (empty($data['content'])) {
                $response->setStatusCode(Response::STATUS_CODE_400);
                $response->setContent(json_encode(array('success' => false)));
                return $response;
            }
            // Create a new job and set the citation style file based on
            // the submitted citation style
            $newJob = $this->jobDAO->getInstance();
            $newJob->user = $this->identity();
            $newJob->setCitationStyleFile($job->getCitationStyleFile());
            $this->jobDAO->save($newJob);
            
            // jats xml declaration
            $xmlheader = '<?xml version="1.0"?>'. PHP_EOL .'<!DOCTYPE article PUBLIC "-//NLM//DTD Journal Publishing DTD v3.0 20080202//EN" "http://dtd.nlm.nih.gov/publishing/3.0/journalpublishing3.dtd">' . PHP_EOL;
            
            // create xml document inside job directory
            $xmlFilePath = $newJob->getUploadPath() . '/document.xml';
            file_put_contents($xmlFilePath, $xmlheader . $data['content']);
            
            // Create new document
            $document = $this->documentDAO->getInstance();
            $document->job = $newJob;
            $document->conversionStage = $newJob->conversionStage;
            $document->path = $xmlFilePath;
            $this->documentDAO->save($document);
            $this->logger->infoTranslate('manager.job.createLog', $newJob->id);
            // Send the job to the queue manager
            $this->queueManager->addJob($newJob->id);
            $response->setContent(json_encode(array('success' => true, 'jobId' => $newJob->id)));
            return $response;
        }
        else {
            $response = $this->getEvent()->getResponse();
            $response->setHeaders(Headers::fromString(
                "Content-Type: {$document->mimeType}\r\n"
            ));

            $content = file_get_contents($document->path);
            $transformedContent = $this->_ontheFlyGraphicUpdate($content);
            $response->setContent($transformedContent);
            return $response;
        }
    }

    /**
     * Proceed on the fly graphic path replacement
     * 
     *  @param string $xmlString XML String
     *  @return string
     */
    protected function _ontheFlyGraphicUpdate($xmlString)
    {
        $dom = new \DOMDocument();
        if (!$dom->loadXML($xmlString)) {
            return $xmlString;
        }

        $graphics = $dom->getElementsByTagName('graphic');
        if (!$graphics->length) {
            return $xmlString;
        }

        $requestPath = $this->getRequest()->getUri()->getPath();

        foreach ($graphics as $graphic) {
            $href = $requestPath . '/' . $graphic->getAttribute('xlink:href');
            $graphic->setAttribute('xlink:href', $href);
        }

        return $dom->saveXML();
    }

    /**
     * get image file
     */
    public function mediaAction()
    {
        $jobId = (int) $this->params()->fromRoute('id');
        $imageFilename = strval($this->params()->fromRoute('file'));

        $user = $this->identity();
        if (
            !($job = $this->jobDAO->find($jobId)) or
            ($job->user->id != $user->id and !$user->isAdministrator())
        ) {
            $this->getResponse()->setStatusCode(404);
            return;
        }

        class_exists('Manager\Entity\Job');

        $jobParams = array(
            'id' => $jobId,
            'status' => JOB_STATUS_COMPLETED,
            'user' => $user,
        );

        if (!($job = $this->jobDAO->findOneBy($jobParams))) {
            $this->getResponse()->setStatusCode(404);
            return;
        }

        $meTypesetDir = $job->getDocumentPath() . '/metypeset';
        $imageFilepath = $meTypesetDir . '/media/' . $imageFilename;
        if (!file_exists($imageFilepath)) {
            $this->getResponse()->setStatusCode(404);
            return;
        }

        $response = $this->getEvent()->getResponse();
        $response->setHeaders(Headers::fromString(
            "Content-Type: image/png\r\n" .
            "Content-Length: " . filesize($imageFilepath) . "\r\n"
        ));
        $response->setContent(file_get_contents($imageFilepath));

        return $response;
    }
}
