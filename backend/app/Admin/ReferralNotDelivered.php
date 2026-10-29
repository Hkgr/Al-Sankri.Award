<?php

namespace App\Admin;

use RuntimeException;

/**
 * A referral whose assignments were not all created: thrown inside the
 * referral's transaction so the classification and its event roll back.
 */
class ReferralNotDelivered extends RuntimeException {}
