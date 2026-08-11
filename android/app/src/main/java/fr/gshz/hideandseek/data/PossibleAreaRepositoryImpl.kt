package fr.gshz.hideandseek.data

import fr.gshz.hideandseek.core.data.ConnectionStore
import fr.gshz.hideandseek.core.model.NotConnectedException
import fr.gshz.hideandseek.data.remote.HideAndSeekApi
import fr.gshz.hideandseek.domain.repository.PossibleAreaData
import fr.gshz.hideandseek.domain.repository.PossibleAreaRepository
import javax.inject.Inject

class PossibleAreaRepositoryImpl @Inject constructor(
    private val api: HideAndSeekApi,
    private val connectionStore: ConnectionStore,
) : PossibleAreaRepository {

    override suspend fun getPossibleArea(roundUuid: String): PossibleAreaData =
        api.getPossibleArea(url = urlFor("/api/rounds/$roundUuid/possible-area")).let { dto ->
            PossibleAreaData(possibleAreaGeoJson = dto.geoJson, exclusionGeoJson = dto.exclusionGeoJson)
        }

    private suspend fun urlFor(path: String): String =
        (connectionStore.current() ?: throw NotConnectedException()).apiUrl.trimEnd('/') + path
}
