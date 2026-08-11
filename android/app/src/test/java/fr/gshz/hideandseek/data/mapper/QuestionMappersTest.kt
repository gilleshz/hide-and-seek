package fr.gshz.hideandseek.data.mapper

import fr.gshz.hideandseek.data.remote.dto.AskQuestionRequest
import fr.gshz.hideandseek.data.remote.dto.AskedQuestionDto
import fr.gshz.hideandseek.domain.model.PhotoTarget
import fr.gshz.hideandseek.domain.model.QuestionCategory
import kotlinx.serialization.json.Json
import org.junit.jupiter.api.Assertions.assertEquals
import org.junit.jupiter.api.Assertions.assertNull
import org.junit.jupiter.api.Assertions.assertTrue
import org.junit.jupiter.api.Test

class QuestionMappersTest {

    private val json = Json { ignoreUnknownKeys = true }

    @Test
    fun `a traveling thermometer without a deadline decodes and maps with nulls`() {
        val dto = json.decodeFromString<AskedQuestionDto>(
            """
            {"uuid":"q-1","roundUuid":"round-1","category":"thermometer",
             "askedAt":"2026-07-16T10:00:00Z","startLat":48.85,"startLng":2.35,"distanceMeters":1000.0}
            """.trimIndent(),
        )

        val question = requireNotNull(dto.toDomain())

        assertEquals(QuestionCategory.Thermometer, question.category)
        assertNull(question.revealDeadlineAt)
        assertNull(question.endLat)
        assertNull(question.endLng)
        assertNull(question.photoTarget)
        assertEquals(48.85, question.startLat!!, 0.0)
        assertEquals(2.35, question.startLng!!, 0.0)
        assertEquals(1000.0, question.distanceMeters!!, 0.0)
    }

    @Test
    fun `a radar question maps its radius and seeker point`() {
        val dto = json.decodeFromString<AskedQuestionDto>(
            """
            {"uuid":"q-2","roundUuid":"round-1","category":"radar","askedAt":"2026-07-16T10:00:00Z",
             "revealDeadlineAt":"2026-07-16T10:05:00Z","radiusMeters":500.0,"seekerLat":48.8,"seekerLng":2.3}
            """.trimIndent(),
        )

        val question = requireNotNull(dto.toDomain())

        assertEquals("2026-07-16T10:05:00Z", question.revealDeadlineAt)
        assertEquals(500.0, question.radiusMeters!!, 0.0)
        assertEquals(48.8, question.seekerLat!!, 0.0)
        assertEquals(2.3, question.seekerLng!!, 0.0)
    }

    @Test
    fun `a photo question maps its target to the catalog entry`() {
        val dto = json.decodeFromString<AskedQuestionDto>(
            """
            {"uuid":"q-3","roundUuid":"round-1","category":"photos",
             "askedAt":"2026-07-16T10:00:00Z","revealDeadlineAt":"2026-07-16T10:10:00Z","photoTarget":"tree"}
            """.trimIndent(),
        )

        assertEquals(PhotoTarget.Tree, requireNotNull(dto.toDomain()).photoTarget)
    }

    @Test
    fun `an unknown photo target maps to null instead of throwing`() {
        val dto = AskedQuestionDto(
            uuid = "q-4",
            roundUuid = "round-1",
            category = "photos",
            askedAt = "2026-07-16T10:00:00Z",
            photoTarget = "hologram",
        )

        assertNull(requireNotNull(dto.toDomain()).photoTarget)
    }

    @Test
    fun `a transit-line matching question maps its line label`() {
        val dto = json.decodeFromString<AskedQuestionDto>(
            """
            {"uuid":"q-7","roundUuid":"round-1","category":"matching",
             "askedAt":"2026-07-16T10:00:00Z","transitLineLabel":"M4: Porte de Clignancourt"}
            """.trimIndent(),
        )

        assertEquals("M4: Porte de Clignancourt", requireNotNull(dto.toDomain()).transitLineLabel)
    }

    @Test
    fun `a question without a transit-line label maps to null`() {
        val dto = AskedQuestionDto(
            uuid = "q-8",
            roundUuid = "round-1",
            category = "matching",
            askedAt = "2026-07-16T10:00:00Z",
        )

        assertNull(requireNotNull(dto.toDomain()).transitLineLabel)
    }

    @Test
    fun `a station-name-length ask request carries the flag and omits the feature type`() {
        val request = AskQuestionRequest(
            askerPlayerUuid = "player-1",
            category = QuestionCategory.Matching.wireValue,
            seekerLat = 10.0,
            seekerLng = 20.0,
            stationNameLength = true,
        )

        val encoded = json.encodeToString(AskQuestionRequest.serializer(), request)

        assertTrue(encoded.contains("\"stationNameLength\":true"))
        assertNull(request.featureType)
    }

    @Test
    fun `an unknown category maps to null so the question is dropped instead of crashing`() {
        val dto = AskedQuestionDto(
            uuid = "q-5",
            roundUuid = "round-1",
            category = "teleportation",
            askedAt = "2026-07-16T10:00:00Z",
        )

        assertNull(dto.toDomain())
    }

    @Test
    fun `an unknown thermometer answer maps to a null result instead of throwing`() {
        val dto = AskedQuestionDto(
            uuid = "q-6",
            roundUuid = "round-1",
            category = "thermometer",
            askedAt = "2026-07-16T10:00:00Z",
            thermometerAnswer = "lukewarm",
        )

        assertNull(requireNotNull(dto.toDomain()).thermometerResult)
    }
}
