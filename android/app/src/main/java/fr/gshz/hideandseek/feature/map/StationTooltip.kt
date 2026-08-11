package fr.gshz.hideandseek.feature.map

import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.ExperimentalLayoutApi
import androidx.compose.foundation.layout.FlowRow
import androidx.compose.foundation.layout.Spacer
import androidx.compose.foundation.layout.height
import androidx.compose.foundation.layout.offset
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.layout.widthIn
import androidx.compose.foundation.shape.RoundedCornerShape
import androidx.compose.material3.Button
import androidx.compose.material3.Surface
import androidx.compose.material3.Text
import androidx.compose.runtime.Composable
import androidx.compose.runtime.getValue
import androidx.compose.runtime.mutableStateOf
import androidx.compose.runtime.remember
import androidx.compose.runtime.setValue
import androidx.compose.ui.Modifier
import androidx.compose.ui.graphics.Color
import androidx.compose.ui.layout.onGloballyPositioned
import androidx.compose.ui.platform.LocalDensity
import androidx.compose.ui.res.stringResource
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.unit.IntOffset
import androidx.compose.ui.unit.dp
import androidx.compose.ui.unit.sp
import fr.gshz.hideandseek.R
import kotlin.math.roundToInt

private val STATION_TOOLTIP_MAX_WIDTH = 200.dp

@Composable
internal fun StationTooltip(
    label: String,
    lines: List<LineRef>,
    screenX: Float,
    screenY: Float,
    onDismiss: () -> Unit,
) {
    var heightPx by remember { mutableStateOf(0) }
    var widthPx by remember { mutableStateOf(0) }
    val density = LocalDensity.current
    Surface(
        modifier = Modifier
            .offset {
                with(density) {
                    IntOffset(
                        (screenX - widthPx / 2f).roundToInt(),
                        (screenY - heightPx - 8.dp.toPx()).roundToInt(),
                    )
                }
            }
            .onGloballyPositioned {
                heightPx = it.size.height
                widthPx = it.size.width
            }
            .widthIn(max = STATION_TOOLTIP_MAX_WIDTH),
        shape = RoundedCornerShape(8.dp),
        color = Color.Black.copy(alpha = 0.88f),
        shadowElevation = 4.dp,
        onClick = onDismiss,
    ) {
        Column(modifier = Modifier.padding(10.dp)) {
            Text(label, color = Color.White, fontWeight = FontWeight.Bold, fontSize = 14.sp)
            if (lines.isNotEmpty()) {
                Spacer(Modifier.height(6.dp))
                LineBadges(lines)
            }
        }
    }
}

@OptIn(ExperimentalLayoutApi::class)
@Composable
private fun LineBadges(lines: List<LineRef>) {
    FlowRow(
        horizontalArrangement = Arrangement.spacedBy(6.dp),
        verticalArrangement = Arrangement.spacedBy(4.dp),
    ) {
        lines.forEach { line ->
            Surface(
                shape = RoundedCornerShape(4.dp),
                color = remember(line.color) { toLineColor(line.color) },
            ) {
                Text(
                    line.ref,
                    modifier = Modifier.padding(horizontal = 6.dp, vertical = 2.dp),
                    color = Color.White,
                    fontSize = 12.sp,
                    fontWeight = FontWeight.Bold,
                )
            }
        }
    }
}

private const val ACTION_TOOLTIP_ALPHA = 0.92f

@Composable
internal fun StationActionTooltip(
    action: SeekerActionData,
    onMark: () -> Unit,
    onUnmark: (String) -> Unit,
    onDismiss: () -> Unit,
) {
    var heightPx by remember { mutableStateOf(0) }
    val density = LocalDensity.current
    Surface(
        modifier = Modifier
            .offset {
                with(density) {
                    IntOffset(
                        (action.screenX - 80.dp.toPx()).roundToInt(),
                        (action.screenY - heightPx - 8.dp.toPx()).roundToInt(),
                    )
                }
            }
            .onGloballyPositioned { heightPx = it.size.height },
        shape = RoundedCornerShape(8.dp),
        color = Color.Black.copy(alpha = ACTION_TOOLTIP_ALPHA),
        shadowElevation = 4.dp,
        onClick = onDismiss,
    ) {
        Column(modifier = Modifier.padding(10.dp)) {
            Text(action.label, color = Color.White, fontWeight = FontWeight.Bold, fontSize = 14.sp)
            Spacer(Modifier.height(8.dp))
            val existingUuid = action.existingMarkerUuid
            Button(onClick = { if (existingUuid != null) onUnmark(existingUuid) else onMark() }) {
                Text(
                    stringResource(
                        if (existingUuid != null) R.string.seeker_marker_unmark else R.string.seeker_marker_mark,
                    ),
                )
            }
        }
    }
}

private val TOOLTIP_NAMED_COLORS = mapOf(
    "red" to "#FF0000",
    "blue" to "#0000FF",
    "green" to "#008000",
    "yellow" to "#FFFF00",
    "orange" to "#FFA500",
    "purple" to "#800080",
    "pink" to "#FFC0CB",
    "brown" to "#A52A2A",
    "black" to "#000000",
    "white" to "#FFFFFF",
    "gray" to "#808080",
    "grey" to "#808080",
    "cyan" to "#00FFFF",
    "magenta" to "#FF00FF",
    "maroon" to "#800000",
    "navy" to "#000080",
    "olive" to "#808000",
    "teal" to "#008080",
    "lime" to "#00FF00",
    "silver" to "#C0C0C0",
    "gold" to "#FFD700",
    "violet" to "#EE82EE",
    "indigo" to "#4B0082",
    "coral" to "#FF7F50",
    "turquoise" to "#40E0D0",
    "crimson" to "#DC143C",
    "darkblue" to "#00008B",
    "darkgreen" to "#006400",
    "darkred" to "#8B0000",
    "lightblue" to "#ADD8E6",
    "lightgreen" to "#90EE90",
    "lightgray" to "#D3D3D3",
    "lightgrey" to "#D3D3D3",
    "darkgray" to "#A9A9A9",
    "darkgrey" to "#A9A9A9",
    "beige" to "#F5F5DC",
    "lavender" to "#E6E6FA",
    "salmon" to "#FA8072",
    "tan" to "#D2B48C",
    "plum" to "#DDA0DD",
    "khaki" to "#F0E68C",
    "orchid" to "#DA70D6",
    "chocolate" to "#D2691E",
    "tomato" to "#FF6347",
)

private fun toLineColor(hex: String): Color = try {
    Color(android.graphics.Color.parseColor("#FF$hex"))
} catch (_: IllegalArgumentException) {
    val named = TOOLTIP_NAMED_COLORS[hex.trim().lowercase()]
    if (named != null) {
        Color(android.graphics.Color.parseColor(named))
    } else {
        Color.Gray
    }
}
