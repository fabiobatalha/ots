<?php

namespace Manager\Entity;

use Doctrine\ORM\Mapping as ORM;
use Xmlps\DataObject\DataObject;
use Doctrine\Common\Collections\ArrayCollection;

define('JOB_STATUS_PENDING', 0);
define('JOB_STATUS_PROCESSING', 1);
define('JOB_STATUS_COMPLETED', 2);
define('JOB_STATUS_FAILED', 3);

define('JOB_CONVERSION_STAGE_UNCONVERTED', 0);
define('JOB_CONVERSION_STAGE_DOCX', 1);
define('JOB_CONVERSION_STAGE_NLMXML', 2);

/**
 * Job
 *
 * @ORM\Entity
 * @ORM\Table(name="job")
 * @ORM\HasLifecycleCallbacks
 */
class Job extends DataObject
{
    /**
     * @ORM\Id
     * @ORM\Column(type="integer")
     * @ORM\GeneratedValue(strategy="AUTO")
     */
    protected $id;

    /**
     * @ORM\ManyToOne(targetEntity="User\Entity\User")
     * @ORM\JoinColumn(name="userId", referencedColumnName="id")
     */
    protected $user;

    /**
     * @ORM\OneToMany(targetEntity="Manager\Entity\Document", mappedBy="job", cascade={"all"})
     */
    protected $documents;

    /**
     * @ORM\Column(type="integer", nullable=false)
     */
    protected $creationDate;

    /**
     * @ORM\Column(type="smallint", nullable=false)
     */
    protected $status;

    /**
     * @ORM\Column(type="integer", nullable=false)
     */
    protected $conversionStage;

    /**
     * Constructor
     *
     * @return void
     */
    public function __construct()
    {
        $this->documents = new ArrayCollection();
    }

    /**
     * Sets the creation date timestamp
     *
     * @return void
     *
     * @ORM\PrePersist
     */
    public function initCreationDate()
    {
        if ($this->creationDate === null) {
            $this->creationDate = time();
        }
    }

    /**
     * Sets the initial status
     *
     * @return void
     *
     * @ORM\PrePersist
     */
    public function initStatus()
    {
        if ($this->status === null) {
            $this->status = JOB_STATUS_PENDING;
        }
    }

    /**
     * Sets the initial conversion stage
     *
     * @return void
     *
     * @ORM\PrePersist
     */
    public function initConversionStage()
    {
        if ($this->conversionStage === null) {
            $this->conversionStage = JOB_CONVERSION_STAGE_UNCONVERTED;
        }
    }


    /**
     * Maps job status to display strings
     *
     * @return array map of job status to display strings
     */
    public function getStatusMap()
    {
        $translator = $this->getServiceLocator()->get('Translator');
        return array(
            JOB_STATUS_PENDING => $translator->translate('manager.job.status.pending'),
            JOB_STATUS_PROCESSING => $translator->translate('manager.job.status.processing'),
            JOB_STATUS_COMPLETED => $translator->translate('manager.job.status.completed'),
            JOB_STATUS_FAILED => $translator->translate('manager.job.status.failed'),
        );
    }

    /**
     * Returns the document storage location for converted documents
     *
     * @return void
     */
    public function getDocumentPath()
    {
        if (!$this->user->id) { throw new \Exception('User id is not set'); }
        if (!$this->id) { throw new \Exception('Job id is not set'); }

        $documentPath = $this->user->getDocumentPath() . '/' . $this->id;
        if (!is_dir($documentPath)) { @mkdir($documentPath, 0777, true); }

        if (!is_dir($documentPath)) {
            throw new \Exception('Couldn\'t create document directory');
        }

        return $documentPath;
    }
}