<?php

namespace humhub\modules\calendar;

use humhub\modules\calendar\models\CalendarEntryType;
use Yii;
use humhub\modules\calendar\helpers\Url;
use humhub\modules\calendar\interfaces\CalendarService;
use humhub\modules\content\components\ContentContainerModule;
use humhub\modules\space\models\Space;
use humhub\modules\user\models\User;
use humhub\modules\calendar\models\CalendarEntry;
use humhub\modules\content\components\ContentContainerActiveRecord;

class Module extends ContentContainerModule
{

    /**
     * @var int Reminder process run interval in minutes
     */
    public $reminderProcessInterval = 15;

    /**
     * @inheritdoc
     */
    public $resourcesPath = 'resources';

    public function init()
    {
        parent::init();
        require_once Yii::getAlias('@calendar/vendor/autoload.php');
    }

    public function getRemidnerProcessIntervalMS()
    {
        return $this->reminderProcessInterval * 60 * 1000;
    }

    /**
     * @inheritdoc
     */
    public function getContentContainerTypes()
    {
        return [
            Space::class,
            User::class,
        ];
    }

    /**
     * @inheritdoc
     */
    public function disable()
    {
        foreach (CalendarEntry::find()->all() as $entry) {
            $entry->delete();
        }

        CalendarEntryType::deleteByModule();
        parent::disable();
    }

    /**
     * @inheritdoc
     */
    public function disableContentContainer(ContentContainerActiveRecord $container)
    {
        parent::disableContentContainer($container);
        foreach (CalendarEntry::find()->contentContainer($container)->all() as $entry) {
            $entry->delete();
        }

        CalendarEntryType::deleteByModule($container);
    }

    /**
     * @inheritdoc
     */
    public function getContentContainerName(ContentContainerActiveRecord $container)
    {
        return Yii::t('CalendarModule.base', 'Calendar');
    }

    /**
     * @inheritdoc
     */
    public function getContentContainerDescription(ContentContainerActiveRecord $container)
    {
        if ($container instanceof Space) {
            return Yii::t('CalendarModule.base', 'Adds an event calendar to this space.');
        } elseif ($container instanceof User) {
            return Yii::t('CalendarModule.base', 'Adds a calendar for private or public events to your profile and main menu.');
        }
    }

    public function getContentContainerConfigUrl(ContentContainerActiveRecord $container)
    {
        return Url::toConfig($container);
    }

    public function getConfigUrl()
    {
        return Url::toConfig();
    }

    /**
     * @inheritdoc
     */
    public function getPermissions($contentContainer = null)
    {
        if ($contentContainer !== null) {
            return [
                new permissions\CreateEntry(),
                new permissions\ManageEntry(),
            ];
        }
        return [];
    }
}
