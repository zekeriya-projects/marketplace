<?php

declare(strict_types=1);

namespace App\Domain\Channels\Results;

use App\Domain\Channels\Enums\PublicationStatus;
use App\Models\ChannelListing;

final readonly class PublicationResult
{
    public function __construct(public PublicationStatus $status, public ?ChannelListing $listing = null) {}

    public function queued(): bool
    {
        return $this->status === PublicationStatus::Queued;
    }
}
