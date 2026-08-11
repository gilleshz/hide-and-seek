package fr.gshz.hideandseek.data

import fr.gshz.hideandseek.core.data.ConnectionStore
import fr.gshz.hideandseek.core.model.NotConnectedException
import fr.gshz.hideandseek.data.mapper.toDomain
import fr.gshz.hideandseek.data.remote.HideAndSeekApi
import fr.gshz.hideandseek.data.remote.dto.AreaRef
import fr.gshz.hideandseek.data.remote.dto.BoundaryPreviewRequest
import fr.gshz.hideandseek.data.remote.dto.GameConfigRequest
import fr.gshz.hideandseek.data.remote.dto.GameCreateRequest
import fr.gshz.hideandseek.data.remote.dto.GtfsLineRef
import fr.gshz.hideandseek.data.remote.dto.GtfsSourceDto
import fr.gshz.hideandseek.data.remote.dto.GtfsSourceUrlRequest
import fr.gshz.hideandseek.data.remote.dto.JoinRequest
import fr.gshz.hideandseek.data.remote.dto.LeaveRequest
import fr.gshz.hideandseek.data.remote.dto.DeleteGameRequest
import fr.gshz.hideandseek.data.remote.dto.TeamRequest
import fr.gshz.hideandseek.data.remote.dto.TransitLineDiscoveryRequest
import fr.gshz.hideandseek.data.remote.dto.TransitLinePreviewRequest
import fr.gshz.hideandseek.data.remote.dto.TransitLineRef
import fr.gshz.hideandseek.domain.model.Edition
import fr.gshz.hideandseek.domain.model.GameSize
import fr.gshz.hideandseek.domain.model.GameSummary
import fr.gshz.hideandseek.domain.model.GtfsRoute
import fr.gshz.hideandseek.domain.model.GtfsSourceState
import fr.gshz.hideandseek.domain.model.JoinResult
import fr.gshz.hideandseek.domain.model.LeaderboardEntry
import fr.gshz.hideandseek.domain.model.Player
import fr.gshz.hideandseek.domain.model.Side
import fr.gshz.hideandseek.domain.model.TeamResult
import fr.gshz.hideandseek.domain.model.TransitLine
import fr.gshz.hideandseek.domain.repository.AreaInfo
import fr.gshz.hideandseek.domain.repository.GameBoundary
import fr.gshz.hideandseek.domain.repository.GameRepository
import fr.gshz.hideandseek.domain.repository.GtfsLineSelection
import okhttp3.MediaType.Companion.toMediaType
import okhttp3.MultipartBody
import okhttp3.RequestBody.Companion.toRequestBody
import retrofit2.HttpException
import javax.inject.Inject
import javax.inject.Named

@Suppress("TooManyFunctions")
class GameRepositoryImpl @Inject constructor(
    private val api: HideAndSeekApi,
    @Named("longTimeout") private val longTimeoutApi: HideAndSeekApi,
    private val connectionStore: ConnectionStore,
) : GameRepository {

    override suspend fun createGame(
        name: String,
        size: GameSize,
        edition: Edition,
        boundary: GameBoundary,
        areas: List<AreaInfo>?,
        selectedTransitLines: List<TransitLine>?,
        selectedGtfsLines: List<GtfsLineSelection>?,
    ): GameSummary = longTimeoutApi.createGame(
        url = urlFor("/api/games"),
        body = GameCreateRequest(
            name = name,
            size = size.wireValue,
            edition = edition.wireValue,
            boundarySwLat = boundary.swLat,
            boundarySwLng = boundary.swLng,
            boundaryNeLat = boundary.neLat,
            boundaryNeLng = boundary.neLng,
            areas = areas?.map { AreaRef(osmType = it.osmType, osmId = it.osmId.toInt()) },
            selectedTransitLines = selectedTransitLines?.map { line ->
                TransitLineRef(
                    osmType = line.osmType,
                    osmId = line.osmId.toIntOrNull() ?: 0,
                    ref = line.ref,
                    name = line.name,
                    nameEn = line.nameEn,
                    colour = line.colour,
                    routeType = line.routeType,
                    network = line.network,
                    operator = line.operator,
                )
            },
            selectedGtfsLines = selectedGtfsLines?.map { selection ->
                GtfsLineRef(
                    gtfsSourceUuid = selection.sourceUuid,
                    routeId = selection.route.routeId,
                    ref = selection.route.ref,
                    name = selection.route.displayName,
                    colour = selection.route.color,
                    routeType = selection.route.routeTypeString,
                    network = selection.route.sourceName,
                    operator = "",
                )
            },
        ),
    ).toDomain()

    override suspend fun uploadGtfsFromUrl(url: String, name: String): GtfsSourceState {
        val dto = api.uploadGtfsFromUrl(
            url = urlFor("/api/gtfs-sources"),
            body = GtfsSourceUrlRequest(url = url, name = name),
        )
        return dto.toDomain()
    }

    override suspend fun uploadGtfsFromFile(
        fileBytes: ByteArray,
        fileName: String,
        name: String,
    ): GtfsSourceState {
        val requestBody = fileBytes.toRequestBody("application/zip".toMediaType())
        val part = MultipartBody.Part.createFormData("file", fileName, requestBody)
        val nameBody = name.toRequestBody("text/plain".toMediaType())
        val dto = api.uploadGtfsFile(
            url = urlFor("/api/gtfs-sources"),
            file = part,
            name = nameBody,
        )
        return dto.toDomain()
    }

    override suspend fun searchAreas(query: String): List<AreaInfo> =
        api.searchAreas(url = urlFor("/api/areas?q=$query")).map { dto ->
            AreaInfo(
                osmId = dto.osmId,
                osmType = dto.osmType,
                displayName = dto.displayName,
                adminLevel = dto.adminLevel,
            )
        }

    override suspend fun discoverTransitLines(areas: List<AreaInfo>, routeTypes: List<String>?): List<TransitLine> =
        api.discoverTransitLines(
            url = urlFor("/api/transit-lines"),
            body = TransitLineDiscoveryRequest(
                areas = areas.map { AreaRef(osmType = it.osmType, osmId = it.osmId.toInt()) },
                routeTypes = routeTypes,
            ),
        ).map { dto ->
            TransitLine(
                osmId = dto.osmId,
                osmType = dto.osmType,
                ref = dto.ref,
                name = dto.name,
                nameEn = dto.nameEn,
                colour = dto.colour,
                routeType = dto.routeType,
                network = dto.network,
                operator = dto.operator,
            )
        }

    override suspend fun boundaryPreview(areas: List<AreaInfo>): String =
        api.boundaryPreview(
            url = urlFor("/api/boundary-preview"),
            body = BoundaryPreviewRequest(
                areas = areas.map { AreaRef(osmType = it.osmType, osmId = it.osmId.toInt()) },
            ),
        ).geoJson

    override suspend fun transitLinePreview(osmIds: Set<String>): String =
        api.transitLinePreview(
            url = urlFor("/api/transit-line-preview"),
            body = TransitLinePreviewRequest(osmIds = osmIds.toList()),
        ).geoJson

    override suspend fun getGame(gameUuid: String): GameSummary =
        api.getGame(url = urlFor("/api/games/$gameUuid")).toDomain()

    override suspend fun getTransitOverlay(gameUuid: String): String? {
        val response = api.getTransitOverlay(urlFor("/api/games/$gameUuid/transit-overlay.geojson"))
        if (!response.isSuccessful) throw HttpException(response)
        // 204 No Content means no overlay was generated for this game.
        val body = response.body() ?: return null
        return body.string().takeIf { it.isNotBlank() }
    }

    override suspend fun joinGame(gameUuid: String, displayName: String, password: String): JoinResult =
        api.joinGame(
            url = urlFor("/api/games/$gameUuid/join"),
            body = JoinRequest(name = displayName, password = password),
        ).toDomain()

    override suspend fun removePlayer(gameUuid: String, playerUuid: String) {
        api.removePlayer(url = urlFor("/api/games/$gameUuid/players/$playerUuid/remove"))
    }

    override suspend fun listPlayers(gameUuid: String): List<Player> =
        api.listPlayers(url = urlFor("/api/games/$gameUuid/players")).map { it.toDomain() }

    override suspend fun leaderboard(gameUuid: String): List<LeaderboardEntry> =
        api.leaderboard(url = urlFor("/api/games/$gameUuid/leaderboard")).map { it.toDomain() }

    override suspend fun chooseTeam(roundUuid: String, playerUuid: String, side: Side): TeamResult =
        api.chooseTeam(
            url = urlFor("/api/rounds/$roundUuid/team"),
            body = TeamRequest(playerUuid = playerUuid, side = side.wireValue),
        ).toDomain()

    override suspend fun updateConfig(
        gameUuid: String,
        name: String?,
        size: GameSize?,
        edition: Edition?,
    ): GameSummary = api.updateGame(
        url = urlFor("/api/games/$gameUuid"),
        body = GameConfigRequest(
            name = name,
            size = size?.wireValue,
            edition = edition?.wireValue,
        ),
    ).toDomain()

    override suspend fun leaveGame(gameUuid: String, playerUuid: String) {
        api.leaveGame(
            url = urlFor("/api/games/$gameUuid/leave"),
            body = LeaveRequest(playerUuid = playerUuid),
        )
    }

    override suspend fun deleteGame(gameUuid: String, playerUuid: String) {
        api.deleteGame(
            url = urlFor("/api/games/$gameUuid/delete"),
            body = DeleteGameRequest(playerUuid = playerUuid),
        )
    }

    private suspend fun urlFor(path: String): String =
        (connectionStore.current() ?: throw NotConnectedException()).apiUrl.trimEnd('/') + path

    private fun GtfsSourceDto.toDomain() = GtfsSourceState(
        uuid = uuid,
        name = name,
        routes = routes.map { dto ->
            GtfsRoute(
                sourceUuid = uuid,
                sourceName = name,
                routeId = dto.routeId,
                shortName = dto.shortName,
                longName = dto.longName,
                routeType = dto.routeType,
                color = dto.color,
                textColor = dto.textColor,
            )
        },
    )
}
