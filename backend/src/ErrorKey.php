<?php

declare(strict_types=1);

namespace App;

/**
 * Machine-readable error keys the app maps to user-facing flows. Identity keys all map to the
 * "re-join" flow and are the disambiguator from the API-key 401 (which carries no errorKey).
 */
final class ErrorKey
{
    public const string IDENTITY_TOKEN_MISSING = 'identity.token_missing';
    public const string IDENTITY_TOKEN_INVALID = 'identity.token_invalid';
    public const string IDENTITY_PLAYER_NOT_FOUND = 'identity.player_not_found';
    public const string IDENTITY_PLAYER_LEFT = 'identity.player_left';
    public const string JOIN_PASSWORD_REQUIRED = 'join.password_required';
    public const string JOIN_PASSWORD_INVALID = 'join.password_invalid';
    public const string ACCOUNT_PASSWORD_INVALID = 'account.password_invalid';
    public const string ACCOUNT_NAME_TAKEN = 'account.name_taken';
    public const string PLAYER_REMOVE_NOT_HOST = 'player.remove_not_host';
    public const string RATE_LIMIT_EXCEEDED = 'rate_limit.exceeded';
    public const string POSSIBLE_AREA_SEEKERS_ONLY = 'possible_area.seekers_only';
    public const string QUESTION_SEEKER_POSITION_REQUIRED = 'question.seeker_position_required';
    public const string CHAT_IMAGE_TOO_LARGE = 'chat_image.too_large';
    public const string HEAVY_WORK_BUSY = 'heavy_work.busy';
}
