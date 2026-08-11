package fr.gshz.hideandseek.fake

import fr.gshz.hideandseek.domain.model.LocationUpdate
import fr.gshz.hideandseek.domain.model.Player
import fr.gshz.hideandseek.domain.repository.ChatEvent
import fr.gshz.hideandseek.domain.repository.ChatDeletedEvent
import fr.gshz.hideandseek.domain.repository.ChatReadEvent
import fr.gshz.hideandseek.domain.repository.EndgameEvent
import fr.gshz.hideandseek.domain.repository.GameEventRepository
import fr.gshz.hideandseek.domain.repository.ManualConstraintAddedEvent
import fr.gshz.hideandseek.domain.repository.ManualConstraintRemovedEvent
import fr.gshz.hideandseek.domain.repository.PossibleAreaEvent
import fr.gshz.hideandseek.domain.repository.ReconnectedEvent
import fr.gshz.hideandseek.domain.repository.RosterChanged
import fr.gshz.hideandseek.domain.repository.SeekerCandidateAdded
import fr.gshz.hideandseek.domain.repository.SeekerCandidateRemoved
import fr.gshz.hideandseek.domain.repository.TimeTrapEvent
import fr.gshz.hideandseek.domain.repository.TimerEvent
import fr.gshz.hideandseek.domain.repository.ZoneChanged
import fr.gshz.hideandseek.domain.repository.ZoneRadiusChanged
import kotlinx.coroutines.flow.MutableSharedFlow
import kotlinx.coroutines.flow.SharedFlow

class FakeGameEventRepository : GameEventRepository {

    private val _locationUpdates = MutableSharedFlow<LocationUpdate>(extraBufferCapacity = 64)
    override val locationUpdates: SharedFlow<LocationUpdate> = _locationUpdates

    private val _timerEvents = MutableSharedFlow<TimerEvent>(extraBufferCapacity = 8)
    override val timerEvents: SharedFlow<TimerEvent> = _timerEvents

    private val _chatEvents = MutableSharedFlow<ChatEvent>(extraBufferCapacity = 64)
    override val chatEvents: SharedFlow<ChatEvent> = _chatEvents

    private val _chatReadEvents = MutableSharedFlow<ChatReadEvent>(extraBufferCapacity = 64)
    override val chatReadEvents: SharedFlow<ChatReadEvent> = _chatReadEvents

    private val _chatDeletedEvents = MutableSharedFlow<ChatDeletedEvent>(extraBufferCapacity = 16)
    override val chatDeletedEvents: SharedFlow<ChatDeletedEvent> = _chatDeletedEvents

    private val _possibleAreaEvents = MutableSharedFlow<PossibleAreaEvent>(extraBufferCapacity = 8)
    override val possibleAreaEvents: SharedFlow<PossibleAreaEvent> = _possibleAreaEvents

    private val _endgameEvents = MutableSharedFlow<EndgameEvent>(extraBufferCapacity = 4)
    override val endgameEvents: SharedFlow<EndgameEvent> = _endgameEvents

    private val _reconnectedEvents = MutableSharedFlow<ReconnectedEvent>(extraBufferCapacity = 4)
    override val reconnectedEvents: SharedFlow<ReconnectedEvent> = _reconnectedEvents

    private val _seekerCandidateAdded = MutableSharedFlow<SeekerCandidateAdded>(extraBufferCapacity = 32)
    override val seekerCandidateAdded: SharedFlow<SeekerCandidateAdded> = _seekerCandidateAdded

    private val _seekerCandidateRemoved = MutableSharedFlow<SeekerCandidateRemoved>(extraBufferCapacity = 32)
    override val seekerCandidateRemoved: SharedFlow<SeekerCandidateRemoved> = _seekerCandidateRemoved

    private val _manualConstraintAdded = MutableSharedFlow<ManualConstraintAddedEvent>(extraBufferCapacity = 32)
    override val manualConstraintAdded: SharedFlow<ManualConstraintAddedEvent> = _manualConstraintAdded

    private val _manualConstraintRemoved = MutableSharedFlow<ManualConstraintRemovedEvent>(extraBufferCapacity = 32)
    override val manualConstraintRemoved: SharedFlow<ManualConstraintRemovedEvent> = _manualConstraintRemoved

    private val _zoneRadiusChanged = MutableSharedFlow<ZoneRadiusChanged>(extraBufferCapacity = 8)
    override val zoneRadiusChanged: SharedFlow<ZoneRadiusChanged> = _zoneRadiusChanged

    private val _zoneChanged = MutableSharedFlow<ZoneChanged>(extraBufferCapacity = 8)
    override val zoneChanged: SharedFlow<ZoneChanged> = _zoneChanged

    private val _timeTrapEvents = MutableSharedFlow<TimeTrapEvent>(extraBufferCapacity = 16)
    override val timeTrapEvents: SharedFlow<TimeTrapEvent> = _timeTrapEvents

    private val _rosterEvents = MutableSharedFlow<RosterChanged>(extraBufferCapacity = 8)
    override val rosterEvents: SharedFlow<RosterChanged> = _rosterEvents

    fun emitTimeTrapEvent(event: TimeTrapEvent) {
        _timeTrapEvents.tryEmit(event)
    }

    fun emitRosterChanged(players: List<Player> = emptyList()) {
        _rosterEvents.tryEmit(RosterChanged(players))
    }

    fun emitReconnected() {
        _reconnectedEvents.tryEmit(ReconnectedEvent)
    }

    fun emitPossibleAreaEvent(event: PossibleAreaEvent) {
        _possibleAreaEvents.tryEmit(event)
    }

    fun emitSeekerCandidateAdded(event: SeekerCandidateAdded) {
        _seekerCandidateAdded.tryEmit(event)
    }

    fun emitSeekerCandidateRemoved(event: SeekerCandidateRemoved) {
        _seekerCandidateRemoved.tryEmit(event)
    }

    fun emitManualConstraintAdded(event: ManualConstraintAddedEvent) {
        _manualConstraintAdded.tryEmit(event)
    }

    fun emitManualConstraintRemoved(event: ManualConstraintRemovedEvent) {
        _manualConstraintRemoved.tryEmit(event)
    }

    fun emitZoneRadiusChanged(event: ZoneRadiusChanged) {
        _zoneRadiusChanged.tryEmit(event)
    }

    fun emitZoneChanged(event: ZoneChanged) {
        _zoneChanged.tryEmit(event)
    }

    fun emitTimerEvent(event: TimerEvent) {
        _timerEvents.tryEmit(event)
    }

    fun emitChatEvent(event: ChatEvent) {
        _chatEvents.tryEmit(event)
    }

    fun emitChatReadEvent(event: ChatReadEvent) {
        _chatReadEvents.tryEmit(event)
    }

    fun emitChatDeletedEvent(event: ChatDeletedEvent) {
        _chatDeletedEvents.tryEmit(event)
    }

    override fun connect(apiUrl: String, mercureToken: String, topics: List<String>) = Unit
    override fun disconnect() = Unit
}
