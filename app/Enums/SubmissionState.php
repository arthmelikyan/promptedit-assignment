<?php

namespace App\Enums;

enum SubmissionState: string
{
    case Pending = 'pending';
    case Submitting = 'submitting';
    case Accepted = 'accepted';
    case Uncertain = 'uncertain';
    case Rejected = 'rejected';
}
