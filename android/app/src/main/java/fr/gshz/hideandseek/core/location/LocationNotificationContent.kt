package fr.gshz.hideandseek.core.location

import androidx.annotation.StringRes
import fr.gshz.hideandseek.R
import fr.gshz.hideandseek.domain.model.RoundStatus

internal data class LocationNotificationContent(
    @StringRes val titleRes: Int,
    @StringRes val textRes: Int,
)

internal fun locationNotificationContent(phase: RoundStatus?, searching: Boolean): LocationNotificationContent = when {
    phase == RoundStatus.Ended -> LocationNotificationContent(
        titleRes = R.string.location_notification_title_paused,
        textRes = R.string.location_notification_paused_hint,
    )
    searching -> LocationNotificationContent(
        titleRes = R.string.location_notification_title,
        textRes = R.string.location_notification_searching_fix,
    )
    else -> LocationNotificationContent(
        titleRes = R.string.location_notification_title,
        textRes = R.string.location_notification_leave_hint,
    )
}
