package fr.gshz.hideandseek.feature.lobby

import fr.gshz.hideandseek.core.model.ErrorType
import fr.gshz.hideandseek.domain.model.Edition
import fr.gshz.hideandseek.domain.model.GameSize
import fr.gshz.hideandseek.domain.model.LeaderboardEntry
import fr.gshz.hideandseek.domain.model.Player
import fr.gshz.hideandseek.domain.model.RoundStatus
import fr.gshz.hideandseek.domain.model.Side

data class LobbyUiState(
    val gameUuid: String = "",
    val gameJoinCode: String? = null,
    val qrPayload: String? = null,
    val gameName: String = "",
    val gameSize: GameSize? = null,
    val gameEdition: Edition? = null,
    val roster: List<Player> = emptyList(),
    val mySide: Side? = null,
    val roundStatus: RoundStatus? = null,
    val isStartingRound: Boolean = false,
    val isLoading: Boolean = true,
    val error: ErrorType? = null,
    val errorDetail: String? = null,
    val errorKey: String? = null,
    val errorArgs: Map<String, String>? = null,
    val hasBoundary: Boolean = false,
    val isCreatingRound: Boolean = false,
    val isStoppingRound: Boolean = false,
    val isLeaving: Boolean = false,
    val isDeleting: Boolean = false,
    val isHost: Boolean = false,
    val navigatedHome: Boolean = false,
    val hidingTimeMinutesInput: String = "",
    val leaderboard: List<LeaderboardEntry> = emptyList(),
    val playerMenuTarget: Player? = null,
    val removeConfirmTarget: Player? = null,
    val isRemovingPlayer: Boolean = false,
    val removePlayerError: ErrorType? = null,
    val removePlayerErrorKey: String? = null,
) {
    val canStartRound: Boolean get() = roundStatus == RoundStatus.Lobby && !isStartingRound
    val allPlayersChoseSide: Boolean get() = roster.isNotEmpty() && roster.all { it.side != null }
    val canCreateNewRound: Boolean get() = roundStatus == RoundStatus.Ended
    val canStopRound: Boolean get() = roundStatus == RoundStatus.Hiding || roundStatus == RoundStatus.Seeking
    val hidingTimeMinutes: Int? get() = hidingTimeMinutesInput.toIntOrNull()
    val hasLeaderboard: Boolean get() = leaderboard.isNotEmpty()
}
