package fr.gshz.hideandseek.domain.repository

import fr.gshz.hideandseek.domain.model.HidingZone
import fr.gshz.hideandseek.domain.model.ZoneCard
import fr.gshz.hideandseek.domain.model.ZonePlacement

interface ZoneRepository {
    /**
     * The zone otherwise only arrives live on the hider topic, so a restarted app has lost it. Null when
     * no zone is set yet. The subscriber token rides on the OkHttp interceptor: it is what proves the
     * caller hides.
     */
    suspend fun currentHidingZone(roundUuid: String): HidingZone?

    suspend fun setHidingZone(
        roundUuid: String,
        playerUuid: String,
        placement: ZonePlacement,
    ): HidingZone

    suspend fun playZoneCard(
        roundUuid: String,
        playerUuid: String,
        card: ZoneCard,
        cardPhotoUri: String,
    ): HidingZone
}
