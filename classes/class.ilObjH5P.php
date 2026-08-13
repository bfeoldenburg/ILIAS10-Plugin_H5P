<?php

declare(strict_types=1);

use srag\Plugins\H5P\Settings\ISettingsRepository;
use srag\Plugins\H5P\Settings\IObjectSettings;

/**
 * @author       Thibeau Fuhrer <thibeau@sr.solutions>
 * @noinspection AutoloadingIssuesInspection
 */
class ilObjH5P extends ilObjectPlugin implements ilLPStatusPluginInterface
{
    /**
     * @var ilH5PRepositoryFactory
     */
    protected $repositories;

    /**
     * @var H5PStorage
     */
    protected $h5p_storage;

    /**
     * @var IObjectSettings
     */
    protected $settings;

    /**
     * @inheritDoc
     */
    public function __construct(int $a_ref_id = 0)
    {
        global $DIC;
        parent::__construct($a_ref_id);

        /** @var $component_factory ilComponentFactory */
        $component_factory = $DIC['component.factory'];
        /** @var $plugin ilH5PPlugin */
        $plugin = $component_factory->getPlugin(ilH5PPlugin::PLUGIN_ID);

        $this->repositories = $plugin->getContainer()->getRepositoryFactory();
        $this->h5p_storage = $plugin->getContainer()->getKernelStorage();
    }

    /**
     * @inheritDoc
     */
    protected function doCreate(bool $clone_mode = false): void
    {
        $object_settings = new ilH5PObjectSettings();
        $object_settings->setObjId($this->getId());

        $this->repositories->settings()->storeObjectSettings($object_settings);
        $this->settings = $object_settings;
    }

    /**
     * @inheritDoc
     */
    protected function doCloneObject(ilObject2 $new_obj, int $a_target_id, ?int $a_copy_id = null): void
    {
        $new_obj->settings = $this->repositories->settings()->cloneObjectSettings($this->settings);
        $new_obj->settings->setObjId($new_obj->getId());

        $this->repositories->settings()->storeObjectSettings($new_obj->settings);

        $contents = $this->repositories->content()->getContentsByObject($this->getId());

        foreach ($contents as $content) {
            $copy = $this->repositories->content()->cloneContent($content);
            $copy->setObjId($new_obj->getId());

            $this->repositories->content()->storeContent($copy);

            $this->h5p_storage->copyPackage(
                $copy->getContentId(),
                $content->getContentId()
            );
        }
    }

    /**
     * @inheritDoc
     */
    protected function doDelete(): void
    {
        // delete object settings
        $settings = $this->repositories->settings()->getObjectSettings($this->getId());
        if (null !== $settings) {
            $this->repositories->settings()->deleteObjectSettings($settings);
        }

        // delete object h5p contents
        $contents = $this->repositories->content()->getContentsByObject($this->getId());
        foreach ($contents as $content) {
            $this->repositories->content()->deleteContent($content);
        }

        // delete object h5p solved stati
        $solved_status_list = $this->repositories->result()->getSolvedStatusListByObject($this->getId());
        foreach ($solved_status_list as $status) {
            $this->repositories->result()->deleteSolvedStatus($status);
        }
    }

    /**
     * @inheritDoc
     */
    protected function doRead(): void
    {
        $this->settings = $this->repositories->settings()->getObjectSettings($this->getId());
    }

    /**
     * @inheritDoc
     */
    protected function doUpdate(): void
    {
        $this->repositories->settings()->storeObjectSettings($this->settings);
    }

    /**
     * @inheritDoc
     */
    final protected function initType(): void
    {
        $this->setType(ilH5PPlugin::PLUGIN_ID);
    }

    /**
     * @return bool
     */
    public function isOnline(): bool
    {
        return $this->settings->isOnline();
    }

    /**
     * @return bool
     */
    public function isSolveOnlyOnce(): bool
    {
        return $this->settings->isSolveOnlyOnce();
    }

    /**
     * @param bool $is_online
     */
    public function setOnline(bool $is_online = true): void
    {
        $this->settings->setOnline($is_online);
    }

    /**
     * @param bool $solve_only_once
     */
    public function setSolveOnlyOnce(bool $solve_only_once): void
    {
        $this->settings->setSolveOnlyOnce($solve_only_once);
    }

    /**
     * @inheritDoc
     */
    public function getLPCompleted(): array
    {
        $completed = [];
        foreach ($this->repositories->result()->getSolvedStatusListByObject($this->getId()) as $status) {
            if ($status->isFinished()) {
                $completed[] = $status->getUserId();
            }
        }

        $number_of_contents = count($this->repositories->content()->getContentsByObject($this->getId()));
        if ($number_of_contents > 0) {
            $completed_content_ids = [];
            foreach ($this->repositories->result()->getResultsByObject($this->getId()) as $result) {
                $completed_content_ids[$result->getUserId()][$result->getContentId()] = true;
            }

            foreach ($completed_content_ids as $user_id => $content_ids) {
                if (count($content_ids) >= $number_of_contents) {
                    $completed[] = $user_id;
                }
            }
        }

        return $this->normalizeUserIds($completed);
    }

    /**
     * @inheritDoc
     */
    public function getLPNotAttempted(): array
    {
        $members = ilObjectLP::getInstance($this->getId())->getMembers();

        return array_values(array_diff(
            $this->normalizeUserIds($members),
            $this->getStartedUserIds()
        ));
    }

    /**
     * H5P completion is independent of a passing score. Consequently the
     * plugin does not use ILIAS' failed learning-progress state.
     *
     * @inheritDoc
     */
    public function getLPFailed(): array
    {
        return [];
    }

    /**
     * @inheritDoc
     */
    public function getLPInProgress(): array
    {
        return array_values(array_diff(
            $this->getStartedUserIds(),
            $this->getLPCompleted()
        ));
    }

    /**
     * @inheritDoc
     */
    public function getLPStatusForUser(int $a_user_id): int
    {
        if ($this->hasUserCompletedObject($a_user_id)) {
            return ilLPStatus::LP_STATUS_COMPLETED_NUM;
        }

        $status = $this->repositories->result()->getSolvedStatus($this->getId(), $a_user_id);
        if (null !== $status ||
            count($this->repositories->result()->getResultsByUserAndObject($a_user_id, $this->getId())) > 0 ||
            count($this->repositories->content()->getContentStatesByObjectAndUser($this->getId(), $a_user_id)) > 0 ||
            count(ilChangeEvent::_lookupReadEvents($this->getId(), $a_user_id)) > 0
        ) {
            return ilLPStatus::LP_STATUS_IN_PROGRESS_NUM;
        }

        return ilLPStatus::LP_STATUS_NOT_ATTEMPTED_NUM;
    }

    /**
     * Returns the percentage of distinct H5P contents completed by a user.
     * ILIAS detects this optional plugin method automatically.
     */
    public function getPercentageForUser(int $a_user_id): int
    {
        $status = $this->repositories->result()->getSolvedStatus($this->getId(), $a_user_id);
        if (null !== $status && $status->isFinished()) {
            return 100;
        }

        $contents = $this->repositories->content()->getContentsByObject($this->getId());
        if ([] === $contents) {
            return 0;
        }

        $completed_content_ids = [];
        foreach ($this->repositories->result()->getResultsByUserAndObject($a_user_id, $this->getId()) as $result) {
            $completed_content_ids[$result->getContentId()] = true;
        }

        return min(100, (int) round(100 * count($completed_content_ids) / count($contents)));
    }

    private function hasUserCompletedObject(int $user_id): bool
    {
        $status = $this->repositories->result()->getSolvedStatus($this->getId(), $user_id);
        if (null !== $status && $status->isFinished()) {
            return true;
        }

        $contents = $this->repositories->content()->getContentsByObject($this->getId());
        if ([] === $contents) {
            return false;
        }

        $completed_content_ids = [];
        foreach ($this->repositories->result()->getResultsByUserAndObject($user_id, $this->getId()) as $result) {
            $completed_content_ids[$result->getContentId()] = true;
        }

        return count($completed_content_ids) >= count($contents);
    }

    /**
     * @return int[]
     */
    private function getStartedUserIds(): array
    {
        $user_ids = [];

        foreach ($this->repositories->result()->getSolvedStatusListByObject($this->getId()) as $status) {
            $user_ids[] = $status->getUserId();
        }
        foreach ($this->repositories->result()->getResultsByObject($this->getId()) as $result) {
            $user_ids[] = $result->getUserId();
        }
        foreach ($this->repositories->content()->getContentStatesByObject($this->getId()) as $state) {
            $user_ids[] = $state->getUserId();
        }
        foreach (ilChangeEvent::_lookupReadEvents($this->getId()) as $event) {
            $user_ids[] = (int) $event['usr_id'];
        }

        return $this->normalizeUserIds($user_ids);
    }

    /**
     * @param mixed[] $user_ids
     * @return int[]
     */
    private function normalizeUserIds(array $user_ids): array
    {
        return array_values(array_unique(array_filter(
            array_map('intval', $user_ids),
            static fn (int $user_id): bool => $user_id > 0
        )));
    }
}
