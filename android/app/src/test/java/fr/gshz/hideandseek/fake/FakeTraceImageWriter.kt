package fr.gshz.hideandseek.fake

import android.net.Uri
import fr.gshz.hideandseek.feature.map.TraceImageWriter
import fr.gshz.hideandseek.feature.map.ZonePin
import kotlinx.coroutines.CompletableDeferred

class FakeTraceImageWriter(private val uri: Uri) : TraceImageWriter {
    var writeCount = 0
    var receivedPolylines: List<List<ZonePin>> = emptyList()

    // Holding the render open is what makes the in-flight window real rather than hypothetical.
    var renderGate: CompletableDeferred<Unit>? = null
    var failure: Throwable? = null

    override suspend fun write(polylines: List<List<ZonePin>>): Uri {
        writeCount++
        receivedPolylines = polylines
        renderGate?.await()
        failure?.let { throw it }
        return uri
    }
}
