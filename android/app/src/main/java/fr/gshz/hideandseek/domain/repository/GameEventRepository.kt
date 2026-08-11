package fr.gshz.hideandseek.domain.repository

import fr.gshz.hideandseek.domain.model.ConstraintMode
import fr.gshz.hideandseek.domain.model.LocationUpdate
import fr.gshz.hideandseek.domain.model.Player
import fr.gshz.hideandseek.domain.model.TimeTrap
import kotlinx.coroutines.flow.SharedFlow

/** Carries every round field the UI reads, so a device applies it instead of refetching the round. */
data class TimerEvent(
    val status: String,
    val hidingPeriodEndsAt: String?,
    val seekingEndedAt: String?,
    val roundUuid: String? = null,
    val hidingPeriodStartedAt: String? = null,
    val bankedSeekingSeconds: Int = 0,
    val inMovePeriod: Boolean = false,
    val hidingTimeSeconds: Int? = null,
    val scoreSeconds: Int? = null,
    val hasHidingZone: Boolean = false,
    val hidingRadiusMeters: Double? = null,
)

data class ChatEvent(
    val uuid: String,
    val senderUuid: String?,
    val senderName: String? = null,
    val messageType: String,
    val body: String?,
    val bodyKey: String? = null,
    val bodyArgs: Map<String, String>? = null,
    val imageRef: String?,
    val createdAt: String,
    val questionUuid: String? = null,
    val replyToUuid: String? = null,
)

data class ChatReadEvent(
    val playerUuid: String,
    val playerName: String?,
    val readUpTo: String,
)

data class ChatDeletedEvent(val messageUuid: String)

data class PossibleAreaEvent(val questionUuid: String)

data class SeekerCandidateAdded(
    val uuid: String,
    val playerUuid: String?,
    val lat: Double,
    val lng: Double,
)

data class SeekerCandidateRemoved(val uuid: String)

data class ManualConstraintAddedEvent(
    val uuid: String,
    val mode: ConstraintMode,
    val geoJson: String,
)

data class ManualConstraintRemovedEvent(val uuid: String)

data class ZoneRadiusChanged(val radiusMeters: Double)

data class ZoneChanged(
    val roundUuid: String?,
    val lat: Double,
    val lng: Double,
    val radiusMeters: Double,
)

/** [action] is the wire verb: placed, detected, sprung or dismissed. */
data class TimeTrapEvent(
    val action: String,
    val trap: TimeTrap,
)

data object EndgameEvent

data object ReconnectedEvent

data class RosterChanged(val players: List<Player>)

interface GameEventRepository {
    val locationUpdates: SharedFlow<LocationUpdate>
    val timerEvents: SharedFlow<TimerEvent>
    val chatEvents: SharedFlow<ChatEvent>
    val chatReadEvents: SharedFlow<ChatReadEvent>
    val chatDeletedEvents: SharedFlow<ChatDeletedEvent>
    val possibleAreaEvents: SharedFlow<PossibleAreaEvent>
    val endgameEvents: SharedFlow<EndgameEvent>
    val reconnectedEvents: SharedFlow<ReconnectedEvent>
    val seekerCandidateAdded: SharedFlow<SeekerCandidateAdded>
    val seekerCandidateRemoved: SharedFlow<SeekerCandidateRemoved>
    val manualConstraintAdded: SharedFlow<ManualConstraintAddedEvent>
    val manualConstraintRemoved: SharedFlow<ManualConstraintRemovedEvent>
    val zoneRadiusChanged: SharedFlow<ZoneRadiusChanged>
    val timeTrapEvents: SharedFlow<TimeTrapEvent>

    // Hider-only topic: carries the station point, which seekers never receive.
    val zoneChanged: SharedFlow<ZoneChanged>
    val rosterEvents: SharedFlow<RosterChanged>

    fun connect(apiUrl: String, mercureToken: String, topics: List<String>)
    fun disconnect()
}
