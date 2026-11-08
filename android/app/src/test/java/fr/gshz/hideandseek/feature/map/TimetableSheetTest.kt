package fr.gshz.hideandseek.feature.map

import fr.gshz.hideandseek.R
import org.junit.jupiter.api.Assertions.assertEquals
import org.junit.jupiter.api.Test

class TimetableSheetTest {

    @Test
    fun `under a minute reads as due`() {
        assertEquals(R.string.timetable_due to emptyList<Int>(), relativeLabelSpec(0))
        assertEquals(R.string.timetable_due to emptyList<Int>(), relativeLabelSpec(59))
    }

    @Test
    fun `under an hour reads as minutes`() {
        assertEquals(R.string.timetable_in_minutes to listOf(1), relativeLabelSpec(60))
        assertEquals(R.string.timetable_in_minutes to listOf(59), relativeLabelSpec(3599))
    }

    @Test
    fun `an hour or more reads as hours and minutes`() {
        assertEquals(R.string.timetable_in_hours_minutes to listOf(1, 0), relativeLabelSpec(3600))
        assertEquals(R.string.timetable_in_hours_minutes to listOf(1, 30), relativeLabelSpec(5400))
        assertEquals(R.string.timetable_in_hours_minutes to listOf(2, 3), relativeLabelSpec(7384))
    }
}
