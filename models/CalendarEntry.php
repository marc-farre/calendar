<?php

namespace humhub\modules\calendar\models;

use humhub\modules\calendar\interfaces\recurrence\RecurrentCalendarEntry;
use humhub\modules\calendar\interfaces\Remindable;
use humhub\modules\user\components\ActiveQueryUser;
use Yii;
use yii\base\Exception;
use DateTime;
use DateTimeZone;
use humhub\modules\calendar\helpers\Url;
use humhub\modules\calendar\helpers\CalendarUtils;
use humhub\modules\calendar\interfaces\CalendarEntryIF;
use humhub\modules\calendar\interfaces\CalendarEntryStatus;
use humhub\modules\calendar\notifications\CanceledEvent;
use humhub\modules\calendar\notifications\EventUpdated;
use humhub\modules\calendar\notifications\ReopenedEvent;
use humhub\modules\calendar\permissions\ManageEntry;
use humhub\modules\calendar\widgets\WallEntry;
use humhub\modules\content\models\Content;
use humhub\modules\content\models\ContentTag;
use humhub\modules\search\interfaces\Searchable;
use humhub\modules\space\models\Membership;
use humhub\modules\space\models\Space;
use humhub\modules\calendar\jobs\ForceParticipation;
use humhub\widgets\Label;
use Sabre\VObject\UUIDUtil;
use humhub\libs\DbDateValidator;
use humhub\modules\content\components\ContentContainerActiveRecord;
use humhub\modules\content\components\ContentActiveRecord;
use humhub\modules\user\models\User;
use yii\db\ActiveQuery;

/**
 * This is the model class for table "calendar_entry".
 *
 * The followings are the available columns in table 'calendar_entry':
 * @property integer $id
 * @property string $title
 * @property string $description
 * @property string $start_datetime
 * @property string $end_datetime
 * @property integer $all_day
 * @property integer $participation_mode
 * @property string $color
 * @property string $uid
 * @property integer $allow_decline
 * @property integer $allow_maybe
 * @property string $participant_info
 * @property integer closed
 * @property integer max_participants
 * @property string rrule
 * @property string exdate
 * @property string location
 * @property string $time_zone The timeZone this entry was saved, note the dates itself are always saved in app timeZone
 */
class CalendarEntry extends ContentActiveRecord implements Searchable, CalendarEntryIF, CalendarEntryStatus, Remindable, RecurrentCalendarEntry
{
    /**
     * @inheritdoc
     */
    public $wallEntryClass = WallEntry::class;

    /**
     * Flag for Entry Form to set this content to public
     */
    public $is_public = Content::VISIBILITY_PUBLIC;

    /**
     * This attributes are used for time input
     */
    public $selected_participants = "";

    /**
     * @inheritdoc
     */
    public $managePermission = ManageEntry::class;

    /**
     * @var CalendarDateFormatter
     */
    public $formatter;

    /**
     * @var array attached files
     */
    public $files = [];

    /**
     * @inheritdoc
     */
    public $canMove = true;

    /**
     * @inheritdoc
     */
    public $moduleId = 'calendar';

    /**
     * Participation Modes
     */
    const PARTICIPATION_MODE_NONE = 0;
    const PARTICIPATION_MODE_INVITE = 1;
    const PARTICIPATION_MODE_ALL = 2;

    /**
     * @var array all given participation modes as array
     */
    public static $participationModes = [
        self::PARTICIPATION_MODE_NONE,
        self::PARTICIPATION_MODE_INVITE,
        self::PARTICIPATION_MODE_ALL
    ];

    /**
     * Filters
     */
    const FILTER_PARTICIPATE = 1;
    const FILTER_NOT_RESPONDED = 3;
    const FILTER_RESPONDED = 4;
    const FILTER_MINE = 5;

    public function init()
    {
        parent::init();

        // Default participiation Mode
        if($this->participation_mode === null) {
            $this->participation_mode = self::PARTICIPATION_MODE_ALL;
        }

        if($this->allow_maybe === null) {
            $this->allow_maybe = 1;
        }

        if($this->allow_decline === null) {
            $this->allow_decline = 1;
        }

        $this->formatter = new CalendarDateFormatter(['calendarItem' => $this]);
    }

    /**
     * @inheritdoc
     */
    public static function tableName()
    {
        return 'calendar_entry';
    }

    /**
     * @inheritdoc
     */
    public function getContentName()
    {
        return Yii::t('CalendarModule.base', "Event");
    }

    /**
     * @inheritdoc
     */
    public function getContentDescription()
    {
        return $this->title;
    }

    /**
     * @inheritdoc
     */
    public function getIcon()
    {
        return 'fa-calendar';
    }

    /**
     * @inheritdoc
     */
    public function getLabels($result = [], $includeContentName = true)
    {
        $labels = [];

        if($this->closed) {
            $labels[] = Label::danger(Yii::t('CalendarModule.base', 'canceled'))->sortOrder(15);
        }

        $type = $this->getType();
        if($type) {
            $labels[] = Label::asColor($type->color, $type->name)->sortOrder(310);
        }

        return parent::getLabels($labels);
    }

    /**
     * @inheritdoc
     */
    public function rules()
    {
        return [
            [['files'], 'safe'],
            [['title', 'start_datetime', 'end_datetime'], 'required'],
            ['color', 'string'],
            [['start_datetime'], DbDateValidator::class],
            [['end_datetime'], DbDateValidator::class],
            [['all_day', 'allow_decline', 'allow_maybe', 'max_participants'], 'integer'],
            [['title'], 'string', 'max' => 200],
            [['participation_mode'], 'in', 'range' => self::$participationModes],
            [['end_datetime'], 'validateEndTime'],
            [['description', 'participant_info'], 'safe'],
        ];
    }

    /**
     * Validator for the endtime field.
     * Execute this after DbDateValidator
     *
     * @param string $attribute attribute name
     * @param array $params parameters
     * @throws \Exception
     */
    public function validateEndTime($attribute, $params)
    {
        if (new DateTime($this->start_datetime) >= new DateTime($this->end_datetime)) {
            $this->addError($attribute, Yii::t('CalendarModule.base', "End time must be after start time!"));
        }
    }

    /**
     * @inheritdoc
     */
    public function attributeLabels()
    {
        return [
            'id' => Yii::t('CalendarModule.base', 'ID'),
            'title' => Yii::t('CalendarModule.base', 'Title'),
            'type_id' => Yii::t('CalendarModule.base', 'Event Type'),
            'description' => Yii::t('CalendarModule.base', 'Description'),
            'all_day' => Yii::t('CalendarModule.base', 'All Day'),
            'allow_decline' => Yii::t('CalendarModule.base', 'Allow participation state \'decline\''),
            'allow_maybe' => Yii::t('CalendarModule.base', 'Allow participation state \'maybe\''),
            'participation_mode' => Yii::t('CalendarModule.base', 'Participation Mode'),
            'max_participants' => Yii::t('CalendarModule.base', 'Maximum number of participants'),
        ];
    }

    /**
     * @inheritdoc
     */
    public function getSearchAttributes()
    {
        return [
            'title' => $this->title,
            'description' => $this->description,
        ];
    }

    public function beforeSave($insert)
    {
        // Check is a full day span
        if ($this->all_day == 0 && CalendarUtils::isFullDaySpan(new DateTime($this->start_datetime), new DateTime($this->end_datetime))) {
            $this->all_day = 1;
        }

        if($this->hasProperty('uid') && empty($this->uid)) {
            $this->uid = static::createUUid();
        }

        return parent::beforeSave($insert);
    }

    public static function createUUid($type = 'event')
    {
        return 'humhub-'.$type.'-' . UUIDUtil::getUUID();
    }

    public function beforeDelete()
    {
        foreach (CalendarEntryParticipant::findAll(['calendar_entry_id' => $this->id]) as $participant) {
            $participant->delete();
        }

        return parent::beforeDelete();
    }

    public function toggleClosed()
    {
        $this->closed = ($this->closed) ? 0 : 1;
        $this->save();

        if($this->closed) {
            $this->sendUpdateNotification(CanceledEvent::class);
        } else {
            $this->sendUpdateNotification(ReopenedEvent::class);
        }
    }

    /**
     * @param string $notificationClass
     * @throws \yii\base\InvalidConfigException
     * @throws \Throwable
     */
    public function sendUpdateNotification($notificationClass = EventUpdated::class)
    {
        $participants = $this->getParticipantUsersByState([
            CalendarEntryParticipant::PARTICIPATION_STATE_MAYBE,
            CalendarEntryParticipant::PARTICIPATION_STATE_ACCEPTED]);

        Yii::createObject(['class' => $notificationClass])->from(Yii::$app->user->getIdentity())->about($this)->sendBulk($participants);
    }

    public function addAllUsers()
    {
        if($this->participation_mode == static::PARTICIPATION_MODE_ALL && $this->canAddAll()) {
            Yii::$app->queue->push(new ForceParticipation(['entry_id' => $this->id, 'originator_id' => Yii::$app->user->getId()]));
        }
    }

    public function canAddAll()
    {
        return  $this->content->container instanceof Space
            && $this->content->container->can(ManageEntry::class);
    }

    /**
     * Returns the related CalendarEntryType relation if given.
     *
     * @return CalendarEntryType
     */
    public function getType()
    {
        return CalendarEntryType::findByContent($this->content)->one();
    }

    /**
     * Sets the clanedarentry type.
     * @param $type
     */
    public function setType($type)
    {
        $type = ($type instanceof ContentTag) ? $type : ContentTag::findOne($type);
        if($type->is(CalendarEntryType::class)) {
            CalendarEntryType::deleteContentRelations($this->content);
            $this->content->addTag($type);
        }
    }

    /**
     * @return ActiveQuery
     */
    public function getParticipants()
    {
        return $this->hasMany(CalendarEntryParticipant::class, ['calendar_entry_id' => 'id']);
    }

    /**
     * Returns an ActiveQuery for all participant user models of this meeting.
     *
     * @return \yii\db\ActiveQuery
     */
    public function getParticipantUsers()
    {
        return $this->hasMany(User::class, ['id' => 'user_id'])->via('participants');
    }

    /**
     * @param $state
     * @return ActiveQueryUser
     */
    public function findParticipantUsersByState($state)
    {
        if(is_int($state)) {
            $state = [$state];
        }

        return $this->hasMany(User::class, ['id' => 'user_id'])->via('participants', function($query) use ($state) {
            /* @var $query ActiveQuery */
            $query->andWhere(['IN', 'calendar_entry_participant.participation_state', $state]);
        });
    }

    /**
     * @return ActiveQueryUser
     */
    public function findUsersByInterest()
    {
        if($this->content->container instanceof Space) {
            switch ($this->participation_mode) {
                case static::PARTICIPATION_MODE_NONE:
                    return Membership::getSpaceMembersQuery($this->content->container);
                case static::PARTICIPATION_MODE_ALL:
                    $userDeclinedQuery = CalendarEntryParticipant::find()
                        ->where('calendar_entry_participant.user_id = user.id')
                        ->andWhere(['=', 'calendar_entry_participant.calendar_entry_id', $this->id])
                        ->andWhere(['IN', 'calendar_entry_participant.participation_state',
                            [CalendarEntryParticipant::PARTICIPATION_STATE_DECLINED]]);
                    $participantQuery = CalendarEntryParticipant::find()
                        ->where('calendar_entry_participant.user_id = user.id')
                        ->andWhere(['=', 'calendar_entry_participant.calendar_entry_id', $this->id])
                        ->andWhere(['IN', 'calendar_entry_participant.participation_state',
                            [CalendarEntryParticipant::PARTICIPATION_STATE_MAYBE, CalendarEntryParticipant::PARTICIPATION_STATE_ACCEPTED]]);

                    return  Membership::getSpaceMembersQuery($this->content->container)
                        ->andWhere(['NOT EXISTS', $userDeclinedQuery])
                        ->orWhere(['EXISTS', $participantQuery]);

                case static::PARTICIPATION_MODE_INVITE:
                    return $this->findParticipantUsersByState([
                        CalendarEntryParticipant::PARTICIPATION_STATE_ACCEPTED,
                        CalendarEntryParticipant::PARTICIPATION_STATE_MAYBE]);
            }
        } elseif ($this->content->container instanceof User) {
            switch ($this->participation_mode) {
                case static::PARTICIPATION_MODE_NONE:
                    return User::find()->where(['id' => $this->content->container->id]);
                case static::PARTICIPATION_MODE_INVITE:
                case static::PARTICIPATION_MODE_ALL:
                    // TODO: remind all friends who did not decline for MODE_ALL
                    return $this->findParticipantUsersByState([
                        CalendarEntryParticipant::PARTICIPATION_STATE_ACCEPTED,
                        CalendarEntryParticipant::PARTICIPATION_STATE_MAYBE])->orWhere(['user.id' => $this->content->container]);

            }
        }

        // Fallback should only happen for global events, which are not supported
        return User::find()->where(['id' => $this->content->createdBy->id]);
    }


    /**
     * @param $state
     * @return CalendarEntry[]
     */
    public function getParticipantUsersByState($state)
    {
        return $this->findParticipantUsersByState($state)->all();
    }

    public function setParticipant($user, $state = CalendarEntryParticipant::PARTICIPATION_STATE_ACCEPTED)
    {
        $participant = $this->findParticipant($user);

        if (!$participant) {
            $participant = new CalendarEntryParticipant([
                'user_id' => $user->id,
                'calendar_entry_id' => $this->id
            ]);
        }

        $participant->participation_state = $state;
        $participant->save();
    }

    /**
     * Finds a participant instance for the given user or the logged in user if no user provided.
     *
     * @param User $user
     * @return CalendarEntryParticipant
     * @throws \Throwable
     */
    public function findParticipant(User $user = null)
    {
        if (!$user) {
            $user = Yii::$app->user->getIdentity();
        }

        if(!$user) {
            return;
        }

        return CalendarEntryParticipant::findOne(['user_id' => $user->id, 'calendar_entry_id' => $this->id]);
    }

    public function isParticipant(User $user = null)
    {
        $participant = $this->findParticipant($user);
        return !empty($participant) && $participant->showParticipantInfo();
    }

    public function getUrl()
    {
        return Url::toEntry($this);
    }

    /**
     * Checks if given or current user can respond to this event
     *
     * @param User $user
     * @return boolean
     * @throws \Throwable
     */
    public function canRespond(User $user = null)
    {
        if ($user == null && !Yii::$app->user->isGuest) {
            $user = Yii::$app->user->getIdentity();
        }

        if ($this->closed || Yii::$app->user->isGuest || !$this->checkMaxParticipants()) {
            return false;
        }

        if ($this->participation_mode == self::PARTICIPATION_MODE_ALL) {
            return true;
        }

        if ($this->participation_mode == self::PARTICIPATION_MODE_INVITE) {
            $participant = CalendarEntryParticipant::findOne(['calendar_entry_id' => $this->id, 'user_id' => $user->id]);
            if ($participant !== null) {
                return true;
            }
        }

        return false;
    }

    public function isParticipationAllowed()
    {
        return $this->participation_mode != self::PARTICIPATION_MODE_NONE;
    }

    public function checkMaxParticipants()
    {
        // Participants always can change/reset their state
        return empty($this->max_participants) || $this->isParticipant()
            || ($this->getParticipantCount(CalendarEntryParticipant::PARTICIPATION_STATE_ACCEPTED) < $this->max_participants);
    }

    /**
     * Checks if given or current user already responded to this event
     *
     * @param User $user
     * @return boolean
     * @throws \Throwable
     */
    public function hasResponded(User $user = null)
    {
        if (Yii::$app->user->isGuest) {
            return false;
        }

        if ($user == null) {
            $user = Yii::$app->user->getIdentity();
        }

        $participant = CalendarEntryParticipant::findOne(['calendar_entry_id' => $this->id, 'user_id' => $user->id]);
        if ($participant !== null) {
            return true;
        }

        return false;
    }

    public function respond($type, User $user = null) {
        if ($user == null && !Yii::$app->user->isGuest) {
            $user = Yii::$app->user->getIdentity();
        }

        // TODO return a calendarEntryParticipant with errors explaining why
        if(!$this->canRespond()) {
            return null;
        }

        $calendarEntryParticipant = $this->findParticipant($user);

        if ($calendarEntryParticipant == null) {
            $calendarEntryParticipant = new CalendarEntryParticipant([
                'user_id' => $user->id,
                'calendar_entry_id' => $this->id]);
        }

        if($type === CalendarEntryParticipant::PARTICIPATION_STATE_NONE) {
            // never explicitly store PARTICIPATION_STATE 0
            $calendarEntryParticipant->delete();
        } else {
            $calendarEntryParticipant->participation_state = $type;
            $calendarEntryParticipant->save();
        }

        return $calendarEntryParticipant;
    }

    public function getParticipationState(User $user = null)
    {
        if (Yii::$app->user->isGuest) {
            return 0;
        }


        if ($user == null) {
            $user = Yii::$app->user->getIdentity();
        }

        $participant = CalendarEntryParticipant::findOne(['user_id' => $user->id, 'calendar_entry_id' => $this->id]);

        if ($participant !== null) {
            return $participant->participation_state;
        }

        return CalendarEntryParticipant::PARTICIPATION_STATE_NONE;
    }

    /**
     * Get events duration in days
     *
     * @return int days
     */
    public function getDurationDays()
    {
        return $this->formatter->getDurationDays();
    }

    /**
     * Checks if the event is currently running.
     */
    public function isRunning()
    {
        return $this->formatter->isRunning();
    }

    /**
     * Checks the offset till the start date.
     */
    public function getOffsetDays()
    {
        return $this->formatter->getOffsetDays();
    }

    public function getParticipantCount($state)
    {
        return $this->getParticipants()->where(['participation_state' => $state])->count();
    }

    /**
     * @inheritdoc
     */
    public function getTimezone()
    {
        return $this->time_zone;
    }

    public function getStartDateTime()
    {
        return new DateTime($this->start_datetime, new DateTimeZone(Yii::$app->timeZone));
    }

    public function getEndDateTime()
    {
        return new DateTime($this->end_datetime, new DateTimeZone(Yii::$app->timeZone));
    }

    public function getFormattedTime($format = 'long')
    {
        return $this->formatter->getFormattedTime($format);
    }

    /**
     * @return boolean weather or not this item spans exactly over a whole day
     */
    public function isAllDay()
    {
        if($this->all_day === null) {
            return true;
        }

        return (boolean) $this->all_day;
    }

    /**
     * Returns all entries filtered by the given $includes and $filters within a given range.
     * Note this function uses an open range which will include all events which start and/or end within the given search interval.
     *
     * @param DateTime $start
     * @param DateTime $end
     * @param array $includes
     * @param array $filters
     * @param int $limit
     * @return CalendarEntry[]
     * @throws Exception
     * @throws \Throwable
     * @see CalendarEntryQuery
     */
    public static function getEntriesByRange(DateTime $start, DateTime $end, $includes = [], $filters = [], $limit = 50)
    {
        // Limit Range to one month
        $interval = $start->diff($end);
        if ($interval->days > 50) {
            throw new Exception('Range maximum exceeded!');
        }

        return CalendarEntryQuery::find()
            ->from($start)->to($end)
            ->filter($filters)
            ->userRelated($includes)
            ->limit($limit)->all();
    }

    /**
     * Access url of the source content or other view
     *
     * @return string the timezone this item was originally saved, note this is
     */
    public function getTitle()
    {
        return $this->title;
    }

    /**
     * Returns a badge for the snippet
     *
     * @return string the timezone this item was originally saved, note this is
     * @throws \Throwable
     */
    public function getBadge()
    {
        $participant = $this->findParticipant();

        if($participant && $this->isParticipationAllowed()) {
            switch($participant->participation_state) {
                case CalendarEntryParticipant::PARTICIPATION_STATE_ACCEPTED:
                    return Label::success(Yii::t('CalendarModule.base', 'Attending'))->right();
                case CalendarEntryParticipant::PARTICIPATION_STATE_MAYBE:
                    if($this->allow_maybe) {
                        return Label::success(Yii::t('CalendarModule.base', 'Interested'))->right();
                    }
            }
        }

        return null;
    }

    public function generateIcs()
    {
        $timezone = Yii::$app->settings->get('timeZone');
        $ics = new ICS($this->title, $this->description,$this->start_datetime, $this->end_datetime, null, null, $timezone, $this->all_day);
        return $ics;
    }

    public function afterMove(ContentContainerActiveRecord $container = null)
    {
        if($container) {
            $spaceMemberQuery = Membership::find()
                ->where('space_membership.user_id = calendar_entry_participant.user_id')
                ->andWhere(['space_membership.space_id' => $container->id]);

            $query = $this->getParticipants()->andWhere(['NOT EXISTS', $spaceMemberQuery]);

            foreach ($query->all() as $nonSpaceMember) {
                try {
                    $nonSpaceMember->delete();
                } catch (\Throwable $e) {
                    Yii::error($e);
                }
            }
        }

    }

    /**
     * @return string
     */
    public function getUid()
    {
        return $this->uid;
    }

    public function setUid($uid)
    {
        $this->uid = $uid;
    }

    public function getRrule()
    {
        return $this->rrule;
    }

    public function getExdate()
    {
        return $this->exdate;
    }

    public function getLocation()
    {
        return $this->location;
    }

    public function getDescription()
    {
        return $this->description;
    }

    /**
     * Access url of the source content or other view
     *
     * @return string the timezone this item was originally saved, note this is
     */
    public function getUpdateUrl()
    {
        return Url::toEditEntryAjax($this);
    }

    /**
     * Check if this calendar entry is editable, for example by checking `$this->content->isEditable()`.
     *
     * @return bool true if this entry is editable, false
     */
    public function isEditable()
    {
        return $this->content->canEdit();
    }

    /**
     * @return string hex color string e.g: '#44B5F6'
     */
    public function getColor()
    {
        return $this->color;
    }

    /**
     * @return mixed
     */
    public function getStatus()
    {
        if($this->closed) {
            return CalendarEntryStatus::STATUS_CANCELLED;
        }

        return CalendarEntryStatus::STATUS_CONFIRMED;
    }

    /**
     * @return Content
     */
    public function getContentRecord()
    {
        return $this->content;
    }

    /**
     * Additional configuration options
     * @return array
     */
    public function getOptions()
    {
        return null;
    }

    /**
     * Access url of the source content or other view
     *
     * @return string the timezone this item was originally saved, note this is
     */
    public function getCalendarViewUrl()
    {
        return Url::toEntry($this, 1);
    }

    /**
     * @return string view mode 'modal', 'blank', 'redirect'
     */
    public function getCalendarViewMode()
    {
        return static::VIEW_MODE_MODAL;
    }

    public function setRrule($rrule)
    {
        $this->rrule = $rrule;
    }

    public function getRecurrenceId()
    {
        // TODO: Implement getRecurrenceId() method.
    }

    public function setRecurrenceId($recurrenceId)
    {
        // TODO: Implement setRecurrenceId() method.
    }

    /**
     * @param $root
     * @param $start
     * @param $end
     * @param $recurrenceId
     * @return mixed
     */
    public function createRecurrence($start, $end, $recurrenceId)
    {
        // TODO: Implement createRecurrence() method.
    }

    public function getId()
    {
        // TODO: Implement getId() method.
    }

    public function getParentId()
    {
        // TODO: Implement getParentId() method.
    }

    public function getRecurrenceViewUrl($cal = false)
    {
        // TODO: Implement getRecurrenceViewUrl() method.
    }
}
