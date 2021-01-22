<?php

/*
 * This file is part of the Kimai time-tracking app.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace App\Controller;

use App\Calendar\DragAndDropSource;
use App\Calendar\Google;
use App\Calendar\GoogleSource;
use App\Calendar\RecentActivitiesSource;
use App\Calendar\TimesheetEntry;
use App\Configuration\SystemConfiguration;
use App\Event\CalendarDragAndDropSourceEvent;
use App\Event\CalendarGoogleSourceEvent;
use App\Event\RecentActivityEvent;
use App\Repository\TimesheetRepository;
use App\Timesheet\TrackingModeService;
use App\Utils\Color;
use Sensio\Bundle\FrameworkExtraBundle\Configuration\Security;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;

/**
 * Controller used to display calendars.
 *
 * @Route(path="/calendar")
 * @Security("is_granted('IS_AUTHENTICATED_REMEMBERED')")
 */
class CalendarController extends AbstractController
{
    /**
     * @var EventDispatcherInterface
     */
    private $dispatcher;

    public function __construct(EventDispatcherInterface $dispatcher)
    {
        $this->dispatcher = $dispatcher;
    }

    /**
     * @Route(path="/", name="calendar", methods={"GET"})
     */
    public function userCalendar(SystemConfiguration $configuration, TrackingModeService $service, TimesheetRepository $repository)
    {
        $mode = $service->getActiveMode();
        $factory = $this->getDateTimeFactory();
        $defaultStart = $factory->createDateTime($configuration->getTimesheetDefaultBeginTime());

        $config = [
            'dayLimit' => $configuration->getCalendarDayLimit(),
            'showWeekNumbers' => $configuration->isCalendarShowWeekNumbers(),
            'showWeekends' => $configuration->isCalendarShowWeekends(),
            'businessDays' => $configuration->getCalendarBusinessDays(),
            'businessTimeBegin' => $configuration->getCalendarBusinessTimeBegin(),
            'businessTimeEnd' => $configuration->getCalendarBusinessTimeEnd(),
            'slotDuration' => $configuration->getCalendarSlotDuration(),
            'timeframeBegin' => $configuration->getCalendarTimeframeBegin(),
            'timeframeEnd' => $configuration->getCalendarTimeframeEnd(),
        ];

        $isPunchMode = !$mode->canEditDuration() && !$mode->canEditBegin() && !$mode->canEditEnd();
        $dragAndDrop = [];

        if ($mode->canEditBegin()) {
            $dragAndDrop = $this->getDragAndDropResources($configuration, $repository);
        }

        return $this->render('calendar/user.html.twig', [
            'config' => $config,
            'dragAndDrop' => $dragAndDrop,
            'google' => $this->getGoogleSources($configuration),
            'now' => $factory->createDateTime(),
            'defaultStartTime' => $defaultStart->format('h:i:s'),
            'is_punch_mode' => $isPunchMode,
            'can_edit_begin' => $mode->canEditBegin(),
            'can_edit_end' => $mode->canEditBegin(),
            'can_edit_duration' => $mode->canEditDuration(),
        ]);
    }

    /**
     * @return DragAndDropSource[]
     */
    private function getDragAndDropResources(SystemConfiguration $configuration, TimesheetRepository $repository): array
    {
        $maxAmount = $configuration->getCalendarDragAndDropMaxEntries();
        $event = new CalendarDragAndDropSourceEvent($this->getUser(), $maxAmount);

        if ($maxAmount > 0) {
            try {
                $data = $repository->getRecentActivities(
                    $this->getUser(),
                    $this->getDateTimeFactory()->createDateTime('-1 year'),
                    $maxAmount
                );

                $recentActivity = new RecentActivityEvent($this->getUser(), $data);
                $this->dispatcher->dispatch($recentActivity);

                $entries = [];
                $colorHelper = new Color();
                foreach ($recentActivity->getRecentActivities() as $timesheet) {
                    $entries[] = new TimesheetEntry($timesheet, $colorHelper->getTimesheetColor($timesheet));
                }

                $event->addSource(new RecentActivitiesSource($entries));
            } catch (\Exception $ex) {
                $this->logException($ex);
            }
        }

        $this->dispatcher->dispatch($event);

        return $event->getSources();
    }

    private function getGoogleSources(SystemConfiguration $configuration): ?Google
    {
        $apiKey = $configuration->getCalendarGoogleApiKey();
        if ($apiKey === null) {
            return null;
        }

        $sources = [];

        foreach ($configuration->getCalendarGoogleSources() as $name => $config) {
            $sources[] = new GoogleSource($name, $config['id'], $config['color']);
        }

        $event = new CalendarGoogleSourceEvent($this->getUser());
        $this->dispatcher->dispatch($event);

        foreach ($event->getSources() as $source) {
            $sources[] = $source;
        }

        return new Google($apiKey, $sources);
    }
}
