<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use srag\Plugins\H5P\Content\IContent;
use srag\Plugins\H5P\Content\IContentRepository;
use srag\Plugins\H5P\IRepositoryFactory;
use srag\Plugins\H5P\Result\IResult;
use srag\Plugins\H5P\Result\IResultRepository;
use srag\Plugins\H5P\Result\ISolvedStatus;

final class ObjH5PLearningProgressTest extends TestCase
{
    public function testFinishedObjectIsCompleted(): void
    {
        $status = $this->createMock(ISolvedStatus::class);
        $status->method('isFinished')->willReturn(true);

        $result_repository = $this->createMock(IResultRepository::class);
        $result_repository->method('getSolvedStatus')->with(42, 7)->willReturn($status);

        $object = $this->getObject($result_repository, $this->createMock(IContentRepository::class));

        self::assertSame(ilLPStatus::LP_STATUS_COMPLETED_NUM, $object->getLPStatusForUser(7));
        self::assertSame(100, $object->getPercentageForUser(7));
    }

    public function testPercentageCountsDistinctCompletedContents(): void
    {
        $result_repository = $this->createMock(IResultRepository::class);
        $result_repository->method('getSolvedStatus')->with(42, 7)->willReturn(null);
        $result_repository->method('getResultsByUserAndObject')->with(7, 42)->willReturn([
            $this->getResult(10),
            $this->getResult(10),
            $this->getResult(11),
        ]);

        $content_repository = $this->createMock(IContentRepository::class);
        $content_repository->method('getContentsByObject')->with(42)->willReturn([
            $this->createMock(IContent::class),
            $this->createMock(IContent::class),
            $this->createMock(IContent::class),
        ]);

        $object = $this->getObject($result_repository, $content_repository);

        self::assertSame(67, $object->getPercentageForUser(7));
        self::assertSame([], $object->getLPFailed());
    }

    public function testAllContentResultsCompleteObjectWithoutExplicitFinish(): void
    {
        $result_repository = $this->createMock(IResultRepository::class);
        $result_repository->method('getSolvedStatus')->with(42, 7)->willReturn(null);
        $result_repository->method('getResultsByUserAndObject')->with(7, 42)->willReturn([
            $this->getResult(10),
            $this->getResult(11),
        ]);

        $content_repository = $this->createMock(IContentRepository::class);
        $content_repository->method('getContentsByObject')->with(42)->willReturn([
            $this->createMock(IContent::class),
            $this->createMock(IContent::class),
        ]);

        $object = $this->getObject($result_repository, $content_repository);

        self::assertSame(ilLPStatus::LP_STATUS_COMPLETED_NUM, $object->getLPStatusForUser(7));
    }

    private function getObject(
        IResultRepository $result_repository,
        IContentRepository $content_repository
    ): ilObjH5P {
        $repositories = $this->createMock(IRepositoryFactory::class);
        $repositories->method('result')->willReturn($result_repository);
        $repositories->method('content')->willReturn($content_repository);

        $reflection = new ReflectionClass(ilObjH5P::class);
        /** @var ilObjH5P $object */
        $object = $reflection->newInstanceWithoutConstructor();

        $id = new ReflectionProperty(ilObject::class, 'id');
        $id->setValue($object, 42);

        $repository_property = new ReflectionProperty(ilObjH5P::class, 'repositories');
        $repository_property->setValue($object, $repositories);

        return $object;
    }

    private function getResult(int $content_id): IResult
    {
        $result = $this->createMock(IResult::class);
        $result->method('getContentId')->willReturn($content_id);

        return $result;
    }
}
