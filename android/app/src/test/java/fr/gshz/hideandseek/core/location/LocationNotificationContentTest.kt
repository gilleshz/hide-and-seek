package fr.gshz.hideandseek.core.location

import fr.gshz.hideandseek.R
import fr.gshz.hideandseek.domain.model.RoundStatus
import org.junit.jupiter.api.Assertions.assertEquals
import org.junit.jupiter.api.Test

class LocationNotificationContentTest {

    @Test
    fun `an ended round shows the paused notification`() {
        val content = locationNotificationContent(RoundStatus.Ended, searching = false)

        assertEquals(R.string.location_notification_title_paused, content.titleRes)
        assertEquals(R.string.location_notification_paused_hint, content.textRes)
    }

    @Test
    fun `a stale fix shows the searching text during a round`() {
        val content = locationNotificationContent(RoundStatus.Hiding, searching = true)

        assertEquals(R.string.location_notification_title, content.titleRes)
        assertEquals(R.string.location_notification_searching_fix, content.textRes)
    }

    @Test
    fun `a normal state explains how to stop sharing`() {
        val content = locationNotificationContent(RoundStatus.Hiding, searching = false)

        assertEquals(R.string.location_notification_title, content.titleRes)
        assertEquals(R.string.location_notification_leave_hint, content.textRes)
    }
}
