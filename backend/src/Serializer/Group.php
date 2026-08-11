<?php

declare(strict_types=1);

namespace App\Serializer;

final class Group
{
    public const string GAME_READ = 'game:read';
    public const string GAME_WRITE = 'game:write';
    public const string PLAYER_READ = 'player:read';
    public const string ACCOUNT_READ = 'account:read';
    public const string ACCOUNT_WRITE = 'account:write';
    public const string ACCOUNT_PASSWORD_WRITE = 'account-password:write';
    public const string JOIN_READ = 'join:read';
    public const string JOIN_WRITE = 'join:write';
    public const string TEAM_READ = 'team:read';
    public const string TEAM_WRITE = 'team:write';
    public const string LOCATION_READ = 'location:read';
    public const string LOCATION_WRITE = 'location:write';
    public const string ROUND_READ = 'round:read';
    public const string ROUND_WRITE = 'round:write';
    public const string LEADERBOARD_READ = 'leaderboard:read';
    public const string ZONE_READ = 'zone:read';
    public const string ZONE_WRITE = 'zone:write';
    public const string CHAT_READ = 'chat:read';
    public const string CHAT_WRITE = 'chat:write';
    public const string CHAT_RECEIPT_READ = 'chat-receipt:read';
    public const string CHAT_RECEIPT_WRITE = 'chat-receipt:write';
    public const string QUESTION_READ = 'question:read';
    public const string QUESTION_WRITE = 'question:write';
    public const string QUESTION_CATALOG_READ = 'question-catalog:read';
    public const string TRANSIT_LINE_READ = 'transit-line:read';
    public const string TRANSIT_LINE_DISCOVERY_WRITE = 'transit-line-discovery:write';
    public const string TRANSIT_LINE_PREVIEW_READ = 'transit-line-preview:read';
    public const string TRANSIT_LINE_PREVIEW_WRITE = 'transit-line-preview:write';
    public const string BOUNDARY_PREVIEW_WRITE = 'boundary-preview:write';
    public const string BOUNDARY_PREVIEW_READ = 'boundary-preview:read';
    public const string LEAVE_READ = 'leave:read';
    public const string LEAVE_WRITE = 'leave:write';
    public const string GAME_DELETE_WRITE = 'game:delete:write';
    public const string QUESTION_PREVIEW_READ = 'question-preview:read';
    public const string QUESTION_PREVIEW_WRITE = 'question-preview:write';
    public const string FEATURE_READ = 'feature:read';
    public const string CLIENT_CONFIG_READ = 'client-config:read';
    public const string GTFS_SOURCE_READ = 'gtfs-source:read';
    public const string GTFS_SOURCE_WRITE = 'gtfs-source:write';
    public const string SEEKER_CANDIDATE_READ = 'seeker-candidate:read';
    public const string SEEKER_CANDIDATE_WRITE = 'seeker-candidate:write';
    public const string POSSIBLE_AREA_CONSTRAINT_READ = 'possible-area-constraint:read';
    public const string POSSIBLE_AREA_CONSTRAINT_WRITE = 'possible-area-constraint:write';
    public const string TIME_TRAP_READ = 'time-trap:read';
    public const string TIME_TRAP_WRITE = 'time-trap:write';
    public const string STREET_NETWORK_READ = 'street-network:read';
    public const string SUBSCRIBER_TOKEN_READ = 'subscriber-token:read';
    public const string PLAYER_REMOVE_READ = 'player-remove:read';
}
