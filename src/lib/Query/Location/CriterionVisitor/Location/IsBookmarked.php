<?php

/**
 * @copyright Copyright (C) Ibexa AS. All rights reserved.
 * @license For full copyright and license information view LICENSE file distributed with this source code.
 */
declare(strict_types=1);

namespace Ibexa\Solr\Query\Location\CriterionVisitor\Location;

use Ibexa\Contracts\Core\Repository\PermissionResolver;
use Ibexa\Contracts\Core\Repository\Values\Content\Query\Criterion;
use Ibexa\Contracts\Solr\Query\CriterionVisitor;
use LogicException;

final class IsBookmarked extends CriterionVisitor
{
    private const SEARCH_FIELD = 'location_bookmarked_user_ids_mid';

    private PermissionResolver $permissionResolver;

    public function __construct(PermissionResolver $permissionResolver)
    {
        $this->permissionResolver = $permissionResolver;
    }

    public function canVisit(Criterion $criterion): bool
    {
        return $criterion instanceof Criterion\Location\IsBookmarked
            && $criterion->operator === Criterion\Operator::EQ;
    }

    public function visit(
        Criterion $criterion,
        CriterionVisitor $subVisitor = null
    ): string {
        if (!is_array($criterion->value)) {
            throw new LogicException('Expected IsBookmarked Criterion value to be an array');
        }

        $userId = $this->permissionResolver
            ->getCurrentUserReference()
            ->getUserId();

        $query = self::SEARCH_FIELD . ':"' . $userId . '"';

        if (!$criterion->value[0]) {
            $query = 'NOT ' . $query;
        }

        return $query;
    }
}
