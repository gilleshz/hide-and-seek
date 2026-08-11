package fr.gshz.hideandseek.domain.repository

import fr.gshz.hideandseek.domain.model.TimeTrap

interface TimeTrapRepository {
    /** Read by both sides: the trap card tells the hider to reveal traps to seekers. */
    suspend fun listTimeTraps(roundUuid: String): List<TimeTrap>

    suspend fun placeTimeTrap(
        roundUuid: String,
        playerUuid: String,
        lat: Double,
        lng: Double,
        cardPhotoUri: String,
    ): TimeTrap

    suspend fun resolveTimeTrap(
        roundUuid: String,
        trapUuid: String,
        playerUuid: String,
        confirmed: Boolean,
    ): TimeTrap
}
