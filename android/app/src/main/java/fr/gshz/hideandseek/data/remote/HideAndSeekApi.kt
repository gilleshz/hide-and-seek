package fr.gshz.hideandseek.data.remote

import fr.gshz.hideandseek.data.remote.dto.AskQuestionRequest
import fr.gshz.hideandseek.data.remote.dto.AskedQuestionDto
import fr.gshz.hideandseek.data.remote.dto.CancelQuestionRequest
import fr.gshz.hideandseek.data.remote.dto.ChangeAccountPasswordRequest
import fr.gshz.hideandseek.data.remote.dto.ChatMessageDto
import fr.gshz.hideandseek.data.remote.dto.ChatMessageReadDto
import fr.gshz.hideandseek.data.remote.dto.ChatReadCursorDto
import fr.gshz.hideandseek.data.remote.dto.CompleteThermometerRequest
import fr.gshz.hideandseek.data.remote.dto.FeatureDto
import fr.gshz.hideandseek.data.remote.dto.AreaSearchResult
import fr.gshz.hideandseek.data.remote.dto.BoundaryPreviewRequest
import fr.gshz.hideandseek.data.remote.dto.BoundaryPreviewResponse
import fr.gshz.hideandseek.data.remote.dto.GameConfigRequest
import fr.gshz.hideandseek.data.remote.dto.GameCreateRequest
import fr.gshz.hideandseek.data.remote.dto.IngestFeaturesResponseDto
import fr.gshz.hideandseek.data.remote.dto.PostChatMessageRequest
import fr.gshz.hideandseek.data.remote.dto.PostChatReadRequest
import fr.gshz.hideandseek.data.remote.dto.GameDto
import fr.gshz.hideandseek.data.remote.dto.HidingZoneDto
import fr.gshz.hideandseek.data.remote.dto.JoinDto
import fr.gshz.hideandseek.data.remote.dto.JoinRequest
import fr.gshz.hideandseek.data.remote.dto.LeaderboardEntryDto
import fr.gshz.hideandseek.data.remote.dto.AddManualConstraintRequest
import fr.gshz.hideandseek.data.remote.dto.ManualConstraintDto
import fr.gshz.hideandseek.data.remote.dto.LeaveRequest
import fr.gshz.hideandseek.data.remote.dto.LeaveResponseDto
import fr.gshz.hideandseek.data.remote.dto.QuestionPreviewRequest
import fr.gshz.hideandseek.data.remote.dto.QuestionPreviewResponseDto
import fr.gshz.hideandseek.data.remote.dto.DeleteGameRequest
import fr.gshz.hideandseek.data.remote.dto.ClientConfigDto
import fr.gshz.hideandseek.data.remote.dto.PossibleAreaDto
import fr.gshz.hideandseek.data.remote.dto.LocationPingRequest
import fr.gshz.hideandseek.data.remote.dto.LocationPingResponse
import fr.gshz.hideandseek.data.remote.dto.PlayerDto
import fr.gshz.hideandseek.data.remote.dto.RevealQuestionRequest
import fr.gshz.hideandseek.data.remote.dto.RoundDto
import fr.gshz.hideandseek.data.remote.dto.RoundStartRequest
import fr.gshz.hideandseek.data.remote.dto.RoundStopRequest
import fr.gshz.hideandseek.data.remote.dto.SubscriberTokenDto
import fr.gshz.hideandseek.data.remote.dto.AddSeekerCandidateMarkerRequest
import fr.gshz.hideandseek.data.remote.dto.SeekerCandidateMarkerDto
import fr.gshz.hideandseek.data.remote.dto.SetHidingZoneRequest
import fr.gshz.hideandseek.data.remote.dto.StreetNetworkDto
import fr.gshz.hideandseek.data.remote.dto.TeamDto
import fr.gshz.hideandseek.data.remote.dto.TeamRequest
import fr.gshz.hideandseek.data.remote.dto.TimeTrapDto
import fr.gshz.hideandseek.data.remote.dto.TimeTrapResolutionRequest
import fr.gshz.hideandseek.data.remote.dto.GtfsSourceDto
import fr.gshz.hideandseek.data.remote.dto.GtfsSourceUrlRequest
import fr.gshz.hideandseek.data.remote.dto.TransitLineDiscoveryRequest
import fr.gshz.hideandseek.data.remote.dto.TransitLineDto
import fr.gshz.hideandseek.data.remote.dto.TransitLinePreviewRequest
import fr.gshz.hideandseek.data.remote.dto.TransitLinePreviewResponse
import okhttp3.MultipartBody
import okhttp3.RequestBody
import okhttp3.ResponseBody
import retrofit2.Response
import retrofit2.http.Body
import retrofit2.http.DELETE
import retrofit2.http.GET
import retrofit2.http.Multipart
import retrofit2.http.PATCH
import retrofit2.http.POST
import retrofit2.http.Part
import retrofit2.http.Query
import retrofit2.http.Url

/**
 * Every call takes a full [Url] instead of a fixed `@GET("path")`: the API host is
 * entered by the user at runtime (Connect screen), not known when Retrofit is built.
 */
@Suppress("TooManyFunctions")
interface HideAndSeekApi {

    @POST
    suspend fun createGame(@Url url: String, @Body body: GameCreateRequest): GameDto

    @GET
    suspend fun getGame(@Url url: String): GameDto

    // Raw GeoJSON payload, not a typed DTO; the server marks it immutable and caches it.
    @GET
    suspend fun getTransitOverlay(@Url url: String): Response<ResponseBody>

    @POST
    suspend fun joinGame(@Url url: String, @Body body: JoinRequest): JoinDto

    @GET
    suspend fun listPlayers(@Url url: String): List<PlayerDto>

    @GET
    suspend fun leaderboard(@Url url: String): List<LeaderboardEntryDto>

    @POST
    suspend fun chooseTeam(@Url url: String, @Body body: TeamRequest): TeamDto

    @POST
    suspend fun postLocation(@Url url: String, @Body body: LocationPingRequest): LocationPingResponse

    @POST
    suspend fun askQuestion(@Url url: String, @Body body: AskQuestionRequest): AskedQuestionDto

    @GET
    suspend fun getQuestion(@Url url: String): AskedQuestionDto

    @GET
    suspend fun listQuestions(@Url url: String): List<AskedQuestionDto>

    @POST
    suspend fun revealQuestion(@Url url: String, @Body body: RevealQuestionRequest): AskedQuestionDto

    @POST
    suspend fun completeThermometer(@Url url: String, @Body body: CompleteThermometerRequest): AskedQuestionDto

    // The backend answers 204 No Content; a DTO return type would make the deserializer throw on the empty body.
    @POST
    suspend fun cancelQuestion(@Url url: String, @Body body: CancelQuestionRequest)

    // Veto returns 204 No Content, same pattern as cancel. The image is the card being played.
    @Multipart
    @POST
    suspend fun vetoQuestion(
        @Url url: String,
        @Part image: MultipartBody.Part,
        @Part("playerUuid") playerUuid: RequestBody,
    )

    @Multipart
    @POST
    suspend fun randomizeQuestion(
        @Url url: String,
        @Part image: MultipartBody.Part,
        @Part("playerUuid") playerUuid: RequestBody,
    ): AskedQuestionDto

    // The card photo is required by the server, so this is multipart rather than JSON.
    @Multipart
    @POST
    suspend fun playZoneCard(
        @Url url: String,
        @Part image: MultipartBody.Part,
        @Part("playerUuid") playerUuid: RequestBody,
        @Part("card") card: RequestBody,
    ): HidingZoneDto

    @GET
    suspend fun getTimeTraps(@Url url: String): List<TimeTrapDto>

    // Same photo rule as the zone cards, so placing a trap is multipart rather than JSON.
    @Multipart
    @POST
    suspend fun placeTimeTrap(
        @Url url: String,
        @Part image: MultipartBody.Part,
        @Part("playerUuid") playerUuid: RequestBody,
        @Part("lat") lat: RequestBody,
        @Part("lng") lng: RequestBody,
    ): TimeTrapDto

    @POST
    suspend fun resolveTimeTrap(@Url url: String, @Body body: TimeTrapResolutionRequest): TimeTrapDto

    @POST
    suspend fun previewQuestion(
        @Url url: String,
        @Body body: QuestionPreviewRequest,
    ): QuestionPreviewResponseDto

    @POST
    suspend fun setHidingZone(@Url url: String, @Body body: SetHidingZoneRequest): HidingZoneDto

    // Hider-only: the subscriber token is the credential, since the API key is shared game-wide.
    // The token now rides on the SubscriberTokenInterceptor for every call.
    @GET
    suspend fun getHidingZone(@Url url: String): HidingZoneDto

    // Hider-only for the same reason as the zone: the street network is centred on it.
    @GET
    suspend fun getStreetNetwork(@Url url: String): StreetNetworkDto

    @GET
    suspend fun getPossibleArea(@Url url: String): PossibleAreaDto

    @POST
    suspend fun postIngestFeatures(@Url url: String): IngestFeaturesResponseDto

    @GET
    suspend fun getRound(@Url url: String): RoundDto

    @POST
    suspend fun startRound(@Url url: String, @Body body: RoundStartRequest): RoundDto

    @POST
    suspend fun stopRound(@Url url: String, @Body body: RoundStopRequest): RoundDto

    @GET
    suspend fun getChatMessages(@Url url: String): List<ChatMessageDto>

    @POST
    suspend fun postChatMessage(@Url url: String, @Body body: PostChatMessageRequest): ChatMessageDto

    @POST
    suspend fun postChatRead(@Url url: String, @Body body: PostChatReadRequest): ChatReadCursorDto

    @GET
    suspend fun getChatReadCursors(@Url url: String): List<ChatReadCursorDto>

    @GET
    suspend fun getChatMessageReads(@Url url: String): List<ChatMessageReadDto>

    // The backend answers 204 No Content; no return type so the deserializer skips the empty body.
    @DELETE
    suspend fun deleteChatMessage(@Url url: String, @Query("playerUuid") playerUuid: String)

    @Multipart
    @POST
    suspend fun postChatImage(
        @Url url: String,
        @Part image: MultipartBody.Part,
        @Part("playerUuid") playerUuid: RequestBody,
        @Part("caption") caption: RequestBody?,
        @Part("replyToUuid") replyToUuid: RequestBody? = null,
    ): ChatMessageDto

    @Multipart
    @POST
    suspend fun answerPhotoQuestion(
        @Url url: String,
        @Part image: MultipartBody.Part,
        @Part("playerUuid") playerUuid: RequestBody,
    ): AskedQuestionDto

    @GET
    suspend fun getSeekerCandidateMarkers(@Url url: String): List<SeekerCandidateMarkerDto>

    @POST
    suspend fun addSeekerCandidateMarker(
        @Url url: String,
        @Body body: AddSeekerCandidateMarkerRequest,
    ): SeekerCandidateMarkerDto

    // The backend answers 204 No Content; no return type so the deserializer skips the empty body.
    @DELETE
    suspend fun deleteSeekerCandidateMarker(@Url url: String)

    @GET
    suspend fun getManualConstraints(@Url url: String): List<ManualConstraintDto>

    @POST
    suspend fun addManualConstraint(
        @Url url: String,
        @Body body: AddManualConstraintRequest,
    ): ManualConstraintDto

    // The backend answers 204 No Content; no return type so the deserializer skips the empty body.
    @DELETE
    suspend fun deleteManualConstraint(@Url url: String, @Query("playerUuid") playerUuid: String)

    @POST
    suspend fun createRound(@Url url: String): RoundDto

    @PATCH
    suspend fun updateGame(@Url url: String, @Body body: GameConfigRequest): GameDto

    @GET
    suspend fun getFeatures(@Url url: String): List<FeatureDto>

    @GET
    suspend fun searchAreas(@Url url: String): List<AreaSearchResult>

    @POST
    suspend fun discoverTransitLines(
        @Url url: String,
        @Body body: TransitLineDiscoveryRequest,
    ): List<TransitLineDto>

    @POST
    suspend fun boundaryPreview(
        @Url url: String,
        @Body body: BoundaryPreviewRequest,
    ): BoundaryPreviewResponse

    @POST
    suspend fun transitLinePreview(
        @Url url: String,
        @Body body: TransitLinePreviewRequest,
    ): TransitLinePreviewResponse

    @POST
    suspend fun leaveGame(@Url url: String, @Body body: LeaveRequest): LeaveResponseDto

    // The backend answers 204 No Content; a DTO return type would make the deserializer throw on the empty body.
    @POST
    suspend fun removePlayer(@Url url: String)

    // The backend answers 204 No Content; a DTO return type would make the deserializer throw on the empty body.
    @POST
    suspend fun changeAccountPassword(
        @Url url: String,
        @Body body: ChangeAccountPasswordRequest,
    )

    @POST
    suspend fun refreshSubscriberToken(@Url url: String): SubscriberTokenDto

    @POST
    suspend fun deleteGame(@Url url: String, @Body body: DeleteGameRequest)

    @GET
    suspend fun clientConfig(@Url url: String): ClientConfigDto

    @Multipart
    @POST
    suspend fun uploadGtfsFile(
        @Url url: String,
        @Part file: MultipartBody.Part,
        @Part("name") name: RequestBody,
    ): GtfsSourceDto

    @POST
    suspend fun uploadGtfsFromUrl(
        @Url url: String,
        @Body body: GtfsSourceUrlRequest,
    ): GtfsSourceDto
}
