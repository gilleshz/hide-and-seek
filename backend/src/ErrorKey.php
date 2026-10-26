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
    public const string PROFILE_CREDENTIAL_INVALID = 'profile.credential_invalid';
    public const string PLAYER_REMOVE_NOT_HOST = 'player.remove_not_host';
    public const string RATE_LIMIT_EXCEEDED = 'rate_limit.exceeded';
    public const string POSSIBLE_AREA_SEEKERS_ONLY = 'possible_area.seekers_only';
    public const string QUESTION_SEEKER_POSITION_REQUIRED = 'question.seeker_position_required';
    public const string CHAT_IMAGE_TOO_LARGE = 'chat_image.too_large';
    public const string HEAVY_WORK_BUSY = 'heavy_work.busy';
    public const string TOO_MANY_AREAS = 'game.too_many_areas';
    public const string AREA_FIT_NO_LINES = 'area_fit.no_lines';
    public const string AREA_FIT_NO_GEOMETRY = 'area_fit.no_geometry';
    public const string AREA_FIT_NO_AREAS = 'area_fit.no_areas';
    public const string AREA_FIT_TOO_MANY_AREAS = 'area_fit.too_many_areas';
    public const string AREA_FIT_TOO_LARGE = 'area_fit.too_large';
    public const string GTFS_SHAPES_MISSING = 'gtfs.shapes_missing';
    public const string GTFS_SOURCE_TOO_LARGE = 'gtfs.source_too_large';
    public const string GTFS_SOURCE_UNCOMPRESSED_TOO_LARGE = 'gtfs.source_uncompressed_too_large';
    public const string GTFS_SELECTION_EMPTY = 'gtfs.selection_empty';
    public const string GTFS_SELECTION_TOO_LARGE = 'gtfs.selection_too_large';
    public const string GTFS_SUBSET_TOO_LARGE = 'gtfs.subset_too_large';
}
