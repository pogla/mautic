<?php

namespace Mautic\NotificationBundle\Model;

use Doctrine\DBAL\Query\QueryBuilder;
use Mautic\CoreBundle\Helper\Chart\ChartQuery;
use Mautic\CoreBundle\Helper\Chart\LineChart;
use Mautic\CoreBundle\Model\AjaxLookupModelInterface;
use Mautic\CoreBundle\Model\FormModel;
use Mautic\LeadBundle\Entity\Lead;
use Mautic\NotificationBundle\Entity\Notification;
use Mautic\NotificationBundle\Entity\Stat;
use Mautic\NotificationBundle\Event\NotificationEvent;
use Mautic\NotificationBundle\Form\Type\MobileNotificationType;
use Mautic\NotificationBundle\Form\Type\NotificationType;
use Mautic\NotificationBundle\NotificationEvents;
use Mautic\PageBundle\Model\TrackableModel;
use Symfony\Component\Form\FormFactory;
use Symfony\Component\HttpKernel\Exception\MethodNotAllowedHttpException;
use Symfony\Contracts\EventDispatcher\Event;

/**
 * Class NotificationModel
 * {@inheritdoc}
 */
class NotificationModel extends FormModel implements AjaxLookupModelInterface
{
    /**
     * @var TrackableModel
     */
    protected $pageTrackableModel;

    /**
     * NotificationModel constructor.
     */
    public function __construct(TrackableModel $pageTrackableModel)
    {
        $this->pageTrackableModel = $pageTrackableModel;
    }

    /**
     * {@inheritdoc}
     *
     * @return \Mautic\NotificationBundle\Entity\NotificationRepository
     */
    public function getRepository()
    {
        return $this->em->getRepository('MauticNotificationBundle:Notification');
    }

    /**
     * @return \Mautic\NotificationBundle\Entity\StatRepository
     */
    public function getStatRepository()
    {
        return $this->em->getRepository('MauticNotificationBundle:Stat');
    }

    /**
     * {@inheritdoc}
     */
    public function getPermissionBase()
    {
        return 'notification:notifications';
    }

    /**
     * Save an array of entities.
     *
     * @param $entities
     * @param $unlock
     *
     * @return array
     */
    public function saveEntities($entities, $unlock = true)
    {
        //iterate over the results so the events are dispatched on each delete
        $batchSize = 20;
        $i         = 0;
        foreach ($entities as $entity) {
            $isNew = ($entity->getId()) ? false : true;

            //set some defaults
            $this->setTimestamps($entity, $isNew, $unlock);

            if ($dispatchEvent = $entity instanceof Notification) {
                $event = $this->dispatchEvent('pre_save', $entity, $isNew);
            }

            $this->getRepository()->saveEntity($entity, false);

            if ($dispatchEvent) {
                $this->dispatchEvent('post_save', $entity, $isNew, $event);
            }

            if (0 === ++$i % $batchSize) {
                $this->em->flush();
            }
        }
        $this->em->flush();
    }

    /**
     * {@inheritdoc}
     *
     * @param Notification|null $entity
     * @param FormFactory       $formFactory
     * @param string|null       $action
     * @param array             $options
     *
     * @return mixed
     *
     * @throws \Symfony\Component\HttpKernel\Exception\NotFoundHttpException
     * @throws MethodNotAllowedHttpException
     */
    public function createForm($entity, $formFactory, $action = null, $options = [])
    {
        if (!$entity instanceof Notification) {
            throw new MethodNotAllowedHttpException(['Notification']);
        }
        if (!empty($action)) {
            $options['action'] = $action;
        }

        $type = false !== strpos($action, 'mobile_') ? MobileNotificationType::class : NotificationType::class;

        return $formFactory->create($type, $entity, $options);
    }

    /**
     * Get a specific entity or generate a new one if id is empty.
     *
     * @param $id
     *
     * @return Notification|null
     */
    public function getEntity($id = null)
    {
        if (null === $id) {
            $entity = new Notification();
        } else {
            $entity = parent::getEntity($id);
        }

        return $entity;
    }

    /**
     * @param string $source
     * @param int    $sourceId
     */
    public function createStatEntry(Notification $notification, Lead $lead, $source = null, $sourceId = null)
    {
        $stat = new Stat();
        $stat->setDateSent(new \DateTime());
        $stat->setLead($lead);
        $stat->setNotification($notification);
        $stat->setSource($source);
        $stat->setSourceId($sourceId);

        $this->getStatRepository()->saveEntity($stat);
    }

    /**
     * {@inheritdoc}
     *
     * @param $action
     * @param $event
     * @param $entity
     * @param $isNew
     *
     * @throws \Symfony\Component\HttpKernel\Exception\MethodNotAllowedHttpException
     */
    protected function dispatchEvent($action, &$entity, $isNew = false, Event $event = null)
    {
        if (!$entity instanceof Notification) {
            throw new MethodNotAllowedHttpException(['Notification']);
        }

        switch ($action) {
            case 'pre_save':
                $name = NotificationEvents::NOTIFICATION_PRE_SAVE;
                break;
            case 'post_save':
                $name = NotificationEvents::NOTIFICATION_POST_SAVE;
                break;
            case 'pre_delete':
                $name = NotificationEvents::NOTIFICATION_PRE_DELETE;
                break;
            case 'post_delete':
                $name = NotificationEvents::NOTIFICATION_POST_DELETE;
                break;
            default:
                return null;
        }

        if ($this->dispatcher->hasListeners($name)) {
            if (empty($event)) {
                $event = new NotificationEvent($entity, $isNew);
                $event->setEntityManager($this->em);
            }

            $this->dispatcher->dispatch($event, $name);

            return $event;
        }

        return null;
    }

    /**
     * Joins the page table and limits created_by to currently logged in user.
     */
    public function limitQueryToCreator(QueryBuilder &$q)
    {
        $q->join('t', MAUTIC_TABLE_PREFIX.'push_notifications', 'p', 'p.id = t.notification_id')
            ->andWhere('p.created_by = :userId')
            ->setParameter('userId', $this->userHelper->getUser()->getId());
    }

    /**
     * Get line chart data of hits.
     *
     * @param char   $unit          {@link php.net/manual/en/function.date.php#refsect1-function.date-parameters}
     * @param string $dateFormat
     * @param array  $filter
     * @param bool   $canViewOthers
     *
     * @return array
     */
    public function getHitsLineChartData($unit, \DateTime $dateFrom, \DateTime $dateTo, $dateFormat = null, $filter = [], $canViewOthers = true)
    {
        $flag = null;

        if (isset($filter['flag'])) {
            $flag = $filter['flag'];
            unset($filter['flag']);
        }

        $chart = new LineChart($unit, $dateFrom, $dateTo, $dateFormat);
        $query = new ChartQuery($this->em->getConnection(), $dateFrom, $dateTo);

        if (!$flag || 'total_and_unique' === $flag) {
            $q = $query->prepareTimeDataQuery('push_notification_stats', 'date_sent', $filter);

            if (!$canViewOthers) {
                $this->limitQueryToCreator($q);
            }

            $data = $query->loadAndBuildTimeData($q);
            $chart->setDataset($this->translator->trans('mautic.notification.show.total.sent'), $data);
        }

        return $chart->render();
    }

    /**
     * @param $idHash
     *
     * @return Stat
     */
    public function getNotificationStatus($idHash)
    {
        return $this->getStatRepository()->getNotificationStatus($idHash);
    }

    /**
     * Search for an notification stat by notification and lead IDs.
     *
     * @param $notificationId
     * @param $leadId
     *
     * @return array
     */
    public function getNotificationStatByLeadId($notificationId, $leadId)
    {
        return $this->getStatRepository()->findBy(
            [
                'notification' => (int) $notificationId,
                'lead'         => (int) $leadId,
            ],
            ['dateSent' => 'DESC']
        );
    }

    /**
     * Get an array of tracked links.
     *
     * @param $notificationId
     *
     * @return array
     */
    public function getNotificationClickStats($notificationId)
    {
        return $this->pageTrackableModel->getTrackableList('notification', $notificationId);
    }

    /**
     * @param        $type
     * @param string $filter
     * @param int    $limit
     * @param int    $start
     * @param array  $options
     *
     * @return array
     */
    public function getLookupResults($type, $filter = '', $limit = 10, $start = 0, $options = [])
    {
        $results = [];
        switch ($type) {
            case 'notification':
                $entities = $this->getRepository()->getNotificationList(
                    $filter,
                    $limit,
                    $start,
                    $this->security->isGranted($this->getPermissionBase().':viewother'),
                    isset($options['notification_type']) ? $options['notification_type'] : null
                );

                foreach ($entities as $entity) {
                    $results[$entity['language']][$entity['id']] = $entity['name'];
                }

                //sort by language
                ksort($results);

                break;
            case 'mobile_notification':
                $entities = $this->getRepository()->getMobileNotificationList(
                    $filter,
                    $limit,
                    $start,
                    $this->security->isGranted($this->getPermissionBase().':viewother'),
                    isset($options['notification_type']) ? $options['notification_type'] : null
                );

                foreach ($entities as $entity) {
                    $results[$entity['language']][$entity['id']] = $entity['name'];
                }

                //sort by language
                ksort($results);

                break;
        }

        return $results;
    }
}
