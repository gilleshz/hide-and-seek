package fr.gshz.hideandseek.feature.chat

import fr.gshz.hideandseek.domain.model.AskedQuestion
import fr.gshz.hideandseek.domain.model.PhotoTarget
import fr.gshz.hideandseek.domain.model.QuestionCategory
import org.junit.jupiter.api.Assertions.assertEquals
import org.junit.jupiter.api.Assertions.assertNull
import org.junit.jupiter.api.Assertions.fail
import org.junit.jupiter.api.Test

class ChatUiStateTraceTest {

    private fun photoQuestion(uuid: String, target: PhotoTarget?) = AskedQuestion(
        uuid = uuid,
        roundUuid = "round-1",
        category = QuestionCategory.Photos,
        askedAt = "2026-07-05T12:00:00Z",
        revealDeadlineAt = "2026-07-05T12:05:00Z",
        revealedAt = null,
        radarAnswer = null,
        thermometerResult = null,
        photoTarget = target,
    )

    private fun stateWith(vararg questions: AskedQuestion) =
        ChatUiState(questionsByUuid = questions.associateBy { it.uuid })

    @Test
    fun `the nearest-street question is traceable`() {
        val state = stateWith(photoQuestion("q1", PhotoTarget.TraceNearestStreet))

        assertEquals(PhotoTarget.TraceNearestStreet, state.traceableTargetOf("q1"))
    }

    @Test
    fun `the streets-traced question is traceable`() {
        val state = stateWith(photoQuestion("q1", PhotoTarget.StreetsTraced))

        assertEquals(PhotoTarget.StreetsTraced, state.traceableTargetOf("q1"))
    }

    @Test
    fun `another photo question is not traceable`() {
        val state = stateWith(photoQuestion("q1", PhotoTarget.Tree))

        assertNull(state.traceableTargetOf("q1"))
    }

    @Test
    fun `a photo question without a target is not traceable`() {
        val state = stateWith(photoQuestion("q1", null))

        assertNull(state.traceableTargetOf("q1"))
    }

    @Test
    fun `an unknown question uuid is not traceable`() {
        val state = stateWith(photoQuestion("q1", PhotoTarget.StreetsTraced))

        assertNull(state.traceableTargetOf("q2"))
    }

    @Test
    fun `no pending photo answer offers no draw action`() {
        val state = stateWith(photoQuestion("q1", PhotoTarget.StreetsTraced))

        assertNull(state.drawTraceHandler(pendingPhotoAnswerUuid = null) { fail("drew $it") })
    }

    @Test
    fun `a pending traceable answer offers a draw action carrying its question uuid`() {
        val state = stateWith(photoQuestion("q1", PhotoTarget.StreetsTraced))
        val drawn = mutableListOf<String>()

        requireNotNull(state.drawTraceHandler("q1", drawn::add)).invoke()

        assertEquals(listOf("q1"), drawn)
    }

    @Test
    fun `a pending answer to a non-traceable photo question offers no draw action`() {
        val state = stateWith(photoQuestion("q1", PhotoTarget.Tree))

        assertNull(state.drawTraceHandler("q1") { fail("drew $it") })
    }
}
